<?php

namespace Tests\Feature;

use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\VideoFormatSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualitySettingsTest extends TestCase
{
    use RefreshDatabase;

    /** 默认设置中的空字符串会被中间件转成 null 并违反既有 string 规则，这里去掉空值得到可提交的完整设置 */
    private function validBody(): array
    {
        $strip = function (array $data) use (&$strip): array {
            foreach ($data as $key => $value) {
                if ($value === '') {
                    unset($data[$key]);
                } elseif (is_array($value)) {
                    $data[$key] = $strip($value);
                }
            }
            return $data;
        };

        return $strip($this->getJson('/api/settings')->json());
    }

    public function test_from_settings_uses_saved_values(): void
    {
        $settings = app(SettingsService::class);
        $settings->put(SettingKey::VIDEO_MAX_RESOLUTION->value, '2160');
        $settings->put(SettingKey::VIDEO_CODEC_PREFERENCE->value, 'av1');

        $args = app(VideoFormatSelector::class)->fromSettings();

        $this->assertStringContainsString('[quality<=120]', $args);
        $this->assertStringContainsString('vcodec:av01', $args);
    }

    public function test_settings_endpoint_returns_quality_defaults(): void
    {
        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('video_max_resolution', 'unlimited')
            ->assertJsonPath('video_codec_preference', 'hevc');
    }

    public function test_settings_endpoint_saves_quality_values(): void
    {
        $body = $this->validBody();
        $body['video_max_resolution']   = '1080';
        $body['video_codec_preference'] = 'avc';

        $this->postJson('/api/settings', $body)->assertOk();

        $this->assertSame('1080', app(SettingsService::class)->get('video_max_resolution'));
        $this->assertSame('avc', app(SettingsService::class)->get('video_codec_preference'));
    }

    public function test_settings_endpoint_rejects_invalid_resolution(): void
    {
        $body = $this->validBody();
        $body['video_max_resolution'] = '999';

        $this->postJson('/api/settings', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video_max_resolution']);
    }
}
