<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The tab strip inside a destination.
 *
 * Each of the six menu destinations holds pages that used to be menu items of their
 * own. They are tabs now, and the strip is defined once here rather than repeated in
 * every page's view: a page names the group it belongs to and gets the same strip its
 * siblings show, with the same labels and the same order, so moving between them never
 * changes what the top of the screen looks like.
 *
 * Route names, not paths - the addresses moved when the menu did, and may again.
 */
class PageTabs extends Component
{
    /**
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    public const GROUPS = [
        'signals' => [
            ['signals', 'AI signals'],
            ['signals.copier', 'Copied'],
            ['analysis', 'Market scan'],
        ],
        'providers' => [
            ['signals.channels', 'Channels'],
            ['signals.accounts', 'Telegram accounts'],
        ],
        'trades' => [
            ['trades.live', 'Open'],
            ['trades.history', 'History'],
            ['analytics', 'Performance'],
        ],
        'auto-trade' => [
            ['setup', 'Connection'],
            ['terminal', 'Terminal'],
            ['broker-accounts', 'Broker accounts'],
            ['settings', 'Risk & filters'],
        ],
        'settings' => [
            ['profile', 'Account'],
            ['logs', 'Activity'],
        ],
    ];

    /** @var array<int, array{0: string, 1: string}> */
    public array $tabs;

    public function __construct(public string $group)
    {
        $this->tabs = self::GROUPS[$group] ?? [];
    }

    public function render(): View
    {
        return view('components.page-tabs');
    }
}
