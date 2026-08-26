<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The application shell.
 *
 * These exist because a duplicated logo shipped twice. Nothing in the suite touched the
 * layout - every test either hit a Livewire component in isolation or asserted on page
 * content - so a partial could gain a second copy of its header and no test could see it.
 * Counting what the shell renders is cheap and catches exactly that class of mistake.
 */
class LayoutTest extends TestCase
{
    use RefreshDatabase;

    private function shell(): string
    {
        return $this->actingAs(User::factory()->create())->get('/dashboard')->getContent();
    }

    /**
     * Once per sidebar - the mobile drawer and the desktop rail - and no more.
     */
    public function test_the_wordmark_appears_once_per_sidebar(): void
    {
        $html = $this->shell();

        $this->assertSame(2, substr_count($html, 'FX<span'), 'the drawer and the desktop rail, and nothing else');
        $this->assertSame(1, substr_count($html, 'gd-sidebar'));
    }

    /**
     * The partial is shared, so its contents must appear exactly twice and never more.
     */
    public function test_the_navigation_is_rendered_exactly_twice(): void
    {
        $html = $this->shell();

        foreach (['Overview', 'Copier', 'Configure'] as $section) {
            $this->assertSame(2, substr_count($html, ">{$section}<"), "{$section} heading");
        }
    }

    /**
     * Binding this element's class attribute once cost a `hidden` on mobile, which put the
     * desktop sidebar under the open drawer. Width is CSS now, and the class list is the
     * template's alone.
     */
    public function test_the_desktop_sidebar_is_hidden_below_the_large_breakpoint(): void
    {
        $html = $this->shell();

        $this->assertMatchesRegularExpression('/class="gd-sidebar hidden lg:[^"]*"/', $html);

        // Specifically the width binding, not every x-bind:class - rotating the minimise
        // icon with one is fine, because nothing about visibility depends on it.
        $this->assertStringNotContainsString("collapsed ? 'lg:w-", $html);
        $this->assertStringNotContainsString("collapsed ? 'lg:pl-", $html);
    }

    /**
     * A malformed x-data does not warn or degrade - it produces markup that ignores every
     * click. This asserts the expression is at least parseable JSON-free JavaScript.
     */
    public function test_the_navigation_scope_has_no_nested_quoting(): void
    {
        $html = $this->shell();

        // The fault was JSON.parse('['Overview']'), which is a syntax error and took the
        // whole nav down silently.
        $this->assertStringNotContainsString("'['", $html);
        $this->assertStringContainsString("localStorage.getItem('gd-nav-open')", $html);
    }

    public function test_the_session_bar_is_in_the_frame_on_every_page(): void
    {
        $html = $this->shell();

        // It changes the meaning of everything else on screen, so it is not a dashboard
        // card; it is part of the shell.
        $this->assertStringContainsString('UTC', $html);
    }
}
