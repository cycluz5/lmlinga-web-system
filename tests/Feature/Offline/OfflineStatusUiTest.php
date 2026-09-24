<?php

namespace Tests\Feature\Offline;

use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineStatusUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_includes_hidden_offline_banner(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-offline-root', $html);
        $this->assertStringContainsString('data-lml-offline-banner', $html);
        $this->assertStringContainsString('data-lml-offline-toast', $html);
        $this->assertStringContainsString('data-lml-offline-dialog', $html);
        $this->assertStringContainsString('data-lml-offline-dialog-edit', $html);
        $this->assertStringContainsString('data-lml-offline-dialog-dismiss', $html);
        $this->assertStringContainsString('data-offline-status-url="'.e(route('offline.status')).'"', $html);
        $this->assertStringContainsString('data-lml-offline-state="checking"', $html);
        $this->assertMatchesRegularExpression('/data-lml-offline-banner[\s\S]*?\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/data-lml-offline-toast[\s\S]*?\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/data-lml-offline-dialog[\s\S]*?\bhidden\b/', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('data-lml-offline-changes', $html);
        $this->assertStringContainsString('data-lml-offline-changes-list', $html);
        $this->assertStringContainsString('Offline Changes', $html);
        $this->assertStringContainsString('data-lml-offline-discard-dialog', $html);
        $this->assertStringContainsString('data-lml-offline-discard-confirm', $html);
        $this->assertStringContainsString("Don't Sync", $html);
    }

    public function test_household_profiling_and_spot_mapping_share_the_offline_chrome(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        foreach ([
            route('household-profiling.index'),
            route('spot-mapping.index'),
            route('announcements.index'),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-lml-offline-root', $html);
            $this->assertStringContainsString('data-lml-offline-banner', $html);
        }
    }

    public function test_guest_login_does_not_render_offline_chrome(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-lml-offline-root', $html);
        $this->assertStringNotContainsString('data-lml-offline-banner', $html);
        $this->assertStringNotContainsString('data-lml-offline-dialog', $html);
    }

    public function test_first_paint_does_not_claim_online_before_client_probe(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-lml-offline-topbar-label[^>]*>Checking<\/span>/', $html);
        $this->assertStringContainsString('lml-topbar__status--checking', $html);
        $this->assertStringContainsString('Connection status: checking', $html);
        $this->assertStringContainsString('Log Out', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-lml-offline-topbar-label[^>]*>\s*Online\s*<\/span>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-lml-offline-topbar-label[^>]*>\s*Offline\s*<\/span>/',
            $html
        );
    }

    public function test_client_hooks_exist_and_do_not_call_sync(): void
    {
        $js = file_get_contents(resource_path('js/offline/offline-status.js'));
        $this->assertNotFalse($js);

        $this->assertStringContainsString("OFFLINE: 'lmlinga:offline'", $js);
        $this->assertStringContainsString("ONLINE: 'lmlinga:online'", $js);
        $this->assertStringContainsString("SAVED: 'lmlinga:offline-saved'", $js);
        $this->assertStringContainsString("STORAGE_FAILED: 'lmlinga:offline-storage-failed'", $js);
        $this->assertStringContainsString("SYNC_START: 'lmlinga:sync-start'", $js);
        $this->assertStringContainsString("SYNC_SUCCESS: 'lmlinga:sync-success'", $js);
        $this->assertStringContainsString("SYNC_RETRY: 'lmlinga:sync-retry'", $js);
        $this->assertStringContainsString("SYNC_ATTENTION: 'lmlinga:sync-attention'", $js);
        $this->assertStringContainsString("on(win, 'offline'", $js);
        $this->assertStringContainsString("on(win, 'online'", $js);
        $this->assertStringContainsString('confirmServerSession', $js);
        $this->assertStringContainsString('keepOfflineUntilSuccess', $js);
        $this->assertStringContainsString('/offline/status', $js);
        $this->assertStringNotContainsString("method: 'POST'", $js);
        $this->assertDoesNotMatchRegularExpression("/['\"]\\/offline\\/sync['\"]/", $js);
    }

    public function test_sensitive_values_are_never_rendered_in_offline_ui(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $js = (string) file_get_contents(resource_path('js/offline/offline-status.js'));
        $blade = (string) file_get_contents(resource_path('views/components/lml/offline-status.blade.php'));

        foreach ([$html, $blade] as $surface) {
            $this->assertStringNotContainsString('LMLINGA_AT_REST_KEY', $surface);
            $this->assertStringNotContainsString('APP_KEY', $surface);
        }

        $appKey = (string) config('app.key');
        if ($appKey !== '') {
            $this->assertStringNotContainsString($appKey, $html);
            $this->assertStringNotContainsString($appKey, $js);
        }

        $atRestKey = (string) config('lmlinga.at_rest.key');
        if ($atRestKey !== '') {
            $this->assertStringNotContainsString($atRestKey, $html);
            $this->assertStringNotContainsString($atRestKey, $js);
        }

        $this->assertStringNotContainsString('csrf_token()', $blade);
        $this->assertDoesNotMatchRegularExpression('/name="password"/', $html);
        $this->assertStringNotContainsString(csrf_token(), $js);
    }

    public function test_app_bootstrap_loads_offline_status_module(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $this->assertNotFalse($appJs);
        $this->assertStringContainsString("import './offline/offline-status';", $appJs);
        $this->assertStringContainsString("import './offline/offline-client';", $appJs);
        $this->assertStringContainsString("import './offline/offline-sw-register';", $appJs);
    }
}
