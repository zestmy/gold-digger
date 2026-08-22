"""
Static checks for the MQL5 sources.

The Expert Advisor cannot be compiled in CI - MetaEditor is Windows-only - so nothing
catches a broken build until somebody opens it on a Windows machine. These are the two
classes of fault a text scan can find reliably, and both are ones a compiler would
otherwise be the first to notice:

  1. StringFormat/PrintFormat calls whose argument count does not match the format
     string. In MQL5 this does not fail loudly; it prints nonsense, and every wire
     payload in this EA is built with StringFormat.

  2. GD* functions that are called but never defined, which in MQL5 means the file
     simply will not compile.

Neither replaces a compiler. They exist so the obvious mistakes are caught by a push
rather than by the person sitting down to commission the bot.

Usage:
    python scripts/check_mql5.py mql5/Experts/GoldDigger/*.mq5 mql5/Include/GoldDigger/*.mqh

Exits non-zero when something is wrong, so CI fails on it.
"""

import io, re, sys

BS = chr(92)


def strip_comments(src):
    """Remove // and /* */ comments, keeping string literals intact."""
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and i + 1 < n and src[i + 1] == '/':
            while i < n and src[i] != '\n':
                i += 1
        elif c == '/' and i + 1 < n and src[i + 1] == '*':
            i += 2
            while i + 1 < n and not (src[i] == '*' and src[i + 1] == '/'):
                i += 1
            i += 2
        elif c == '"':
            out.append(c)
            i += 1
            while i < n:
                if src[i] == BS:
                    out.append(src[i:i + 2])
                    i += 2
                    continue
                out.append(src[i])
                if src[i] == '"':
                    i += 1
                    break
                i += 1
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def match_paren(s, start):
    """start is index of '('; return index of the matching ')'."""
    depth = 0
    i = start
    n = len(s)
    while i < n:
        c = s[i]
        if c == '"':
            i += 1
            while i < n:
                if s[i] == BS:
                    i += 2
                    continue
                if s[i] == '"':
                    break
                i += 1
        elif c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return -1


def split_args(s):
    """Split a top-level argument list on commas."""
    args, depth, cur, i, n = [], 0, [], 0, len(s)
    while i < n:
        c = s[i]
        if c == '"':
            cur.append(c)
            i += 1
            while i < n:
                if s[i] == BS:
                    cur.append(s[i:i + 2])
                    i += 2
                    continue
                cur.append(s[i])
                if s[i] == '"':
                    i += 1
                    break
                i += 1
            continue
        if c in '([':
            depth += 1
        elif c in ')]':
            depth -= 1
        if c == ',' and depth == 0:
            args.append(''.join(cur))
            cur = []
            i += 1
            continue
        cur.append(c)
        i += 1
    if ''.join(cur).strip():
        args.append(''.join(cur))
    return args


LITERAL = re.compile(r'"((?:[^"' + BS + BS + r']|' + BS + BS + r'.)*)"')
SPEC = re.compile(r'%[-+ #0]*[0-9*]*(?:\.[0-9*]+)?(?:I64|ll|l|h)?([diouxXeEfgGscp%])')


def count_specifiers(fmt_expr):
    """Concatenate the string literals in the first argument and count conversions."""
    literal = ''.join(m.group(1) for m in LITERAL.finditer(fmt_expr))
    return sum(1 for m in SPEC.finditer(literal) if m.group(1) != '%')


def check(path):
    raw = io.open(path, encoding='utf-8').read()
    src = strip_comments(raw)
    problems = []

    # ---- 1. format specifier counts ----
    for fname in ('StringFormat', 'PrintFormat'):
        for m in re.finditer(r'\b' + fname + r'\s*\(', src):
            open_paren = m.end() - 1
            close = match_paren(src, open_paren)
            if close == -1:
                problems.append('%s: unbalanced %s( at offset %d' % (path, fname, open_paren))
                continue
            args = split_args(src[open_paren + 1:close])
            if not args:
                continue
            specs = count_specifiers(args[0])
            supplied = len(args) - 1
            if specs != supplied:
                line = src[:open_paren].count('\n') + 1
                problems.append(
                    '%s:%d  %s expects %d argument(s), %d supplied'
                    % (path, line, fname, specs, supplied))

    # ---- 2. every GD* function called is defined somewhere in the sources ----
    defined = set(re.findall(r'^[A-Za-z_][A-Za-z0-9_ *&]*?\b(GD[A-Za-z0-9_]*)\s*\(', src, re.M))
    called = set(re.findall(r'\b(GD[A-Za-z0-9_]*)\s*\(', src))
    return problems, defined, called


files = sys.argv[1:]
all_problems = []
all_defined = set()
all_called = set()

for f in files:
    p, d, c = check(f)
    all_problems += p
    all_defined |= d
    all_called |= c

# GD_ prefixed macros are #defines, not functions.
macros = set()
for f in files:
    macros |= set(re.findall(r'#define\s+(GD_[A-Za-z0-9_]*)', io.open(f, encoding='utf-8').read()))

undefined = sorted(n for n in all_called - all_defined if n not in macros and not n.startswith('GD_'))

print('=== format specifier / argument counts ===')
if all_problems:
    for p in all_problems:
        print('  MISMATCH', p)
else:
    print('  all StringFormat/PrintFormat calls balanced')

print('=== GD* functions called but never defined ===')
print('  ' + (', '.join(undefined) if undefined else 'none'))

sys.exit(1 if (all_problems or undefined) else 0)
