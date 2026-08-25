<?php

namespace Tests\Feature\Bot;

use App\Livewire\Pages\TerminalSetup;
use App\Models\BotToken;
use App\Models\BrokerAccount;
use App\Models\TradeCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Getting a terminal connected.
 *
 * Two properties carry the weight: the downloaded EA points at the dashboard that served
 * it, and it does not carry the credential. Everything else here is convenience.
 */
class TerminalSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BrokerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = BrokerAccount::create([
            'user_id' => $this->user->id, 'label' => 'Elev8 Demo', 'broker_name' => 'Elev8',
            'account_number' => '230070844', 'server' => 'Elev8-Demo2',
            'is_demo' => true, 'is_active' => true,
        ]);

        $this->actingAs($this->user);
    }

    // =====================================================================
    // THE DOWNLOAD
    // =====================================================================

    /**
     * The step that otherwise gets skipped: editing MQL5 by hand before a first compile.
     */
    public function test_the_downloaded_ea_points_at_this_dashboard(): void
    {
        config(['app.url' => 'https://fx.example.com']);

        $zip = $this->extract($this->get(route('terminal.download')));

        $this->assertStringContainsString(
            'input string   ApiBaseUrl    = "https://fx.example.com"',
            $zip['MQL5/Experts/GoldDigger/GoldDiggerBridge.mq5'],
        );
    }

    public function test_the_committed_source_is_left_as_a_placeholder(): void
    {
        // Baking one deployment's hostname into the repository would be wrong for anyone
        // else running it.
        $this->assertStringNotContainsString(
            'fx.example.com',
            file_get_contents(base_path('mql5/Experts/GoldDigger/GoldDiggerBridge.mq5')),
        );
    }

    /**
     * A credential inside a file travels with the file.
     */
    public function test_the_archive_carries_no_token(): void
    {
        [$plaintext] = BotToken::generate($this->user, 'Terminal', $this->account);

        $zip = $this->extract($this->get(route('terminal.download')));

        foreach ($zip as $name => $contents) {
            $this->assertStringNotContainsString($plaintext, $contents, "{$name} contains a live token");
            $this->assertStringNotContainsString('gd_', $contents === '' ? 'x' : substr($contents, 0, 200000));
        }
    }

    public function test_the_archive_has_the_terminals_folder_layout(): void
    {
        // Extracting over the data folder has to merge, not create a stray directory.
        $zip = $this->extract($this->get(route('terminal.download')));

        $this->assertArrayHasKey('MQL5/Experts/GoldDigger/GoldDiggerBridge.mq5', $zip);
        $this->assertArrayHasKey('MQL5/Include/GoldDigger/GDExecutor.mqh', $zip);
    }

    public function test_the_readme_names_the_wire_version_and_the_url_to_whitelist(): void
    {
        config(['app.url' => 'https://fx.example.com']);

        $zip = $this->extract($this->get(route('terminal.download')));

        $this->assertStringContainsString(TradeCommand::WIRE_VERSION, $zip['README.txt']);
        $this->assertStringContainsString('https://fx.example.com', $zip['README.txt']);
    }

    public function test_the_download_requires_a_login(): void
    {
        auth()->logout();

        $this->get(route('terminal.download'))->assertRedirect();
    }

    // =====================================================================
    // THE TOKEN
    // =====================================================================

    public function test_issuing_a_token_shows_it_once(): void
    {
        $component = Livewire::test(TerminalSetup::class)
            ->set('tokenName', 'Windows VPS')
            ->set('brokerAccountId', $this->account->id)
            ->call('issueToken');

        $token = $component->get('issuedToken');

        $this->assertNotNull($token);
        $component->assertSee($token);
        $this->assertDatabaseHas('bot_tokens', ['name' => 'Windows VPS', 'broker_account_id' => $this->account->id]);
    }

    /**
     * The property that makes a dashboard compromise leak no working credentials.
     */
    public function test_only_a_hash_is_stored(): void
    {
        $token = Livewire::test(TerminalSetup::class)->call('issueToken')->get('issuedToken');

        $this->assertNotSame($token, BotToken::first()->token_hash);
        $this->assertSame(0, BotToken::where('token_hash', $token)->count());
    }

    public function test_an_existing_token_is_never_displayed(): void
    {
        [$plaintext] = BotToken::generate($this->user, 'Already issued', $this->account);

        // The page lists it by name, and cannot show the secret because it does not have it.
        Livewire::test(TerminalSetup::class)
            ->assertSee('Already issued')
            ->assertDontSee($plaintext);
    }

    public function test_a_revoked_token_stops_authenticating(): void
    {
        [$plaintext, $token] = BotToken::generate($this->user, 'Old terminal', $this->account);

        $this->assertNotNull(BotToken::resolve($plaintext));

        Livewire::test(TerminalSetup::class)->call('revokeToken', $token->id);

        $this->assertNull(BotToken::resolve($plaintext));
    }

    public function test_another_users_token_cannot_be_revoked(): void
    {
        $other = User::factory()->create();
        [$plaintext, $theirs] = BotToken::generate($other, 'Theirs');

        Livewire::test(TerminalSetup::class)->call('revokeToken', $theirs->id);

        $this->assertNotNull(BotToken::resolve($plaintext), 'Another account\'s terminal must keep working.');
    }

    public function test_the_page_shows_the_exact_url_to_whitelist(): void
    {
        config(['app.url' => 'https://fx.example.com/']);

        // Trailing slash trimmed: a path there is the usual cause of error 4014.
        Livewire::test(TerminalSetup::class)->assertViewHas('whitelistUrl', 'https://fx.example.com');
    }

    /**
     * @return array<string, string>
     */
    private function extract($response): array
    {
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'gd-test-');
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;
        $zip->open($path);

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $files;
    }
}
