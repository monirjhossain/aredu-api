<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SettingService
{
    public const KEY_PLATFORM_NAME = 'platform_name';

    public const KEY_LOGO_PATH = 'logo_path';

    public const KEY_CONTACT_INFORMATION = 'contact_information';

    public const KEY_SUPPORT_EMAIL = 'support_email';

    public const KEY_CURRENCY = 'currency';

    public const KEY_NOTIFY_PAYMENT = 'notify_on_payment';

    public const KEY_NOTIFY_ENROLLMENT = 'notify_on_enrollment';

    public const KEY_NOTIFY_EVALUATION = 'notify_on_evaluation';

    public const KEY_NOTIFY_MOCK_RESULT = 'notify_on_mock_result';

    public const KEY_NOTIFY_NEW_LESSON = 'notify_on_new_lesson';

    public const KEY_ABOUT_TEXT = 'about_text';

    public const KEY_LANDING_VIDEOS = 'landing_videos';

    public const KEY_BKASH_QR_PATH = 'bkash_qr_path';

    public const KEY_BKASH_MERCHANT_NUMBER = 'bkash_merchant_number';

    public const KEY_BKASH_PAYMENT_NOTE = 'bkash_payment_note';

    public const DEFAULTS = [
        self::KEY_PLATFORM_NAME => 'AR Education IELTS',
        self::KEY_LOGO_PATH => null,
        self::KEY_CONTACT_INFORMATION => '',
        self::KEY_SUPPORT_EMAIL => '',
        self::KEY_CURRENCY => 'BDT',
        self::KEY_NOTIFY_PAYMENT => '1',
        self::KEY_NOTIFY_ENROLLMENT => '1',
        self::KEY_NOTIFY_EVALUATION => '1',
        self::KEY_NOTIFY_MOCK_RESULT => '1',
        self::KEY_NOTIFY_NEW_LESSON => '1',
        self::KEY_ABOUT_TEXT => 'AR Education helps learners prepare for IELTS with structured video courses, timed Reading and Listening mocks, and teacher-evaluated Writing and Speaking practice.',
        self::KEY_LANDING_VIDEOS => '[]',
        self::KEY_BKASH_QR_PATH => null,
        self::KEY_BKASH_MERCHANT_NUMBER => '',
        self::KEY_BKASH_PAYMENT_NOTE => 'Open bKash, scan this QR, send the exact order amount, then wait for admin confirmation.',
    ];

    public function __construct(private readonly ActivityLogService $activity) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->where('key', $key)->first();

        if ($row === null) {
            return array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : $default;
        }

        return $row->value;
    }

    public function currency(): string
    {
        $currency = (string) $this->get(self::KEY_CURRENCY, 'BDT');

        return $currency !== '' ? $currency : 'BDT';
    }

    public function notificationsEnabled(string $event): bool
    {
        $map = [
            'payment' => self::KEY_NOTIFY_PAYMENT,
            'enrollment' => self::KEY_NOTIFY_ENROLLMENT,
            'evaluation' => self::KEY_NOTIFY_EVALUATION,
            'mock_result' => self::KEY_NOTIFY_MOCK_RESULT,
            'new_lesson' => self::KEY_NOTIFY_NEW_LESSON,
        ];

        $key = $map[$event] ?? null;
        if ($key === null) {
            return true;
        }

        return $this->get($key, '1') === '1';
    }

    /**
     * @return array<string, mixed>
     */
    public function publicBranding(): array
    {
        $logoPath = $this->get(self::KEY_LOGO_PATH);

        return [
            'platform_name' => (string) $this->get(self::KEY_PLATFORM_NAME),
            'logo_url' => $this->logoUrl(is_string($logoPath) ? $logoPath : null),
            'contact_information' => (string) $this->get(self::KEY_CONTACT_INFORMATION),
            'support_email' => (string) $this->get(self::KEY_SUPPORT_EMAIL),
            'currency' => $this->currency(),
            'about' => (string) $this->get(self::KEY_ABOUT_TEXT, ''),
            'videos' => $this->landingVideos(),
            'bkash_payment' => $this->bkashPaymentInfo(),
        ];
    }

    /**
     * @return array{qr_url: ?string, merchant_number: string, note: string}
     */
    public function bkashPaymentInfo(): array
    {
        $qrPath = $this->get(self::KEY_BKASH_QR_PATH);

        return [
            'qr_url' => $this->logoUrl(is_string($qrPath) ? $qrPath : null),
            'merchant_number' => (string) $this->get(self::KEY_BKASH_MERCHANT_NUMBER, ''),
            'note' => (string) $this->get(self::KEY_BKASH_PAYMENT_NOTE, self::DEFAULTS[self::KEY_BKASH_PAYMENT_NOTE]),
        ];
    }

    /**
     * @return list<array{title: string, url: string}>
     */
    public function landingVideos(): array
    {
        $raw = (string) $this->get(self::KEY_LANDING_VIDEOS, '[]');
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($row) => is_array($row) && filled($row['url'] ?? null))
            ->map(fn ($row) => [
                'title' => (string) ($row['title'] ?? 'Video'),
                'url' => (string) $row['url'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function allForAdmin(): array
    {
        $logoPath = $this->get(self::KEY_LOGO_PATH);

        return [
            'platform_name' => (string) $this->get(self::KEY_PLATFORM_NAME),
            'logo_path' => is_string($logoPath) && $logoPath !== '' ? $logoPath : null,
            'logo_url' => $this->logoUrl(is_string($logoPath) ? $logoPath : null),
            'contact_information' => (string) $this->get(self::KEY_CONTACT_INFORMATION),
            'support_email' => (string) $this->get(self::KEY_SUPPORT_EMAIL),
            'currency' => $this->currency(),
            'about_text' => (string) $this->get(self::KEY_ABOUT_TEXT, ''),
            'landing_videos' => $this->landingVideos(),
            'bkash_payment' => $this->bkashPaymentInfo(),
            'bkash_merchant_number' => (string) $this->get(self::KEY_BKASH_MERCHANT_NUMBER, ''),
            'bkash_payment_note' => (string) $this->get(self::KEY_BKASH_PAYMENT_NOTE, self::DEFAULTS[self::KEY_BKASH_PAYMENT_NOTE]),
            'payment_gateways' => $this->paymentGatewayStatus(),
            'course_access_rules' => $this->courseAccessRules(),
            'notification_preferences' => [
                'notify_on_payment' => $this->notificationsEnabled('payment'),
                'notify_on_enrollment' => $this->notificationsEnabled('enrollment'),
                'notify_on_evaluation' => $this->notificationsEnabled('evaluation'),
                'notify_on_mock_result' => $this->notificationsEnabled('mock_result'),
                'notify_on_new_lesson' => $this->notificationsEnabled('new_lesson'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $actor, array $data): array
    {
        $writable = [
            self::KEY_PLATFORM_NAME => $data['platform_name'] ?? null,
            self::KEY_CONTACT_INFORMATION => $data['contact_information'] ?? null,
            self::KEY_SUPPORT_EMAIL => $data['support_email'] ?? null,
            self::KEY_CURRENCY => $data['currency'] ?? null,
            self::KEY_ABOUT_TEXT => $data['about_text'] ?? null,
            self::KEY_BKASH_MERCHANT_NUMBER => array_key_exists('bkash_merchant_number', $data)
                ? (string) ($data['bkash_merchant_number'] ?? '')
                : null,
            self::KEY_BKASH_PAYMENT_NOTE => array_key_exists('bkash_payment_note', $data)
                ? (string) ($data['bkash_payment_note'] ?? '')
                : null,
            self::KEY_NOTIFY_PAYMENT => isset($data['notify_on_payment']) ? ($data['notify_on_payment'] ? '1' : '0') : null,
            self::KEY_NOTIFY_ENROLLMENT => isset($data['notify_on_enrollment']) ? ($data['notify_on_enrollment'] ? '1' : '0') : null,
            self::KEY_NOTIFY_EVALUATION => isset($data['notify_on_evaluation']) ? ($data['notify_on_evaluation'] ? '1' : '0') : null,
            self::KEY_NOTIFY_MOCK_RESULT => isset($data['notify_on_mock_result']) ? ($data['notify_on_mock_result'] ? '1' : '0') : null,
            self::KEY_NOTIFY_NEW_LESSON => isset($data['notify_on_new_lesson']) ? ($data['notify_on_new_lesson'] ? '1' : '0') : null,
        ];

        foreach ($writable as $key => $value) {
            if ($value === null) {
                continue;
            }

            $this->put($key, (string) $value);
        }

        if (array_key_exists('landing_videos', $data) && is_array($data['landing_videos'])) {
            $videos = collect($data['landing_videos'])
                ->filter(fn ($row) => is_array($row) && filled($row['url'] ?? null))
                ->map(fn ($row) => [
                    'title' => mb_substr((string) ($row['title'] ?? 'Video'), 0, 120),
                    'url' => mb_substr((string) $row['url'], 0, 500),
                ])
                ->values()
                ->all();
            $this->put(self::KEY_LANDING_VIDEOS, json_encode($videos, JSON_UNESCAPED_UNICODE));
        }

        $this->activity->record(
            $actor,
            'settings.updated',
            null,
            'System settings updated.',
        );

        return $this->allForAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function storeLogo(User $actor, UploadedFile $file): array
    {
        $path = $file->store('platform-logo', 'public');
        $previous = $this->get(self::KEY_LOGO_PATH);

        $this->put(self::KEY_LOGO_PATH, $path);

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        $this->activity->record(
            $actor,
            'settings.logo_updated',
            null,
            'Platform logo updated.',
        );

        return $this->allForAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteLogo(User $actor): array
    {
        $previous = $this->get(self::KEY_LOGO_PATH);

        if (is_string($previous) && $previous !== '') {
            Storage::disk('public')->delete($previous);
        }

        $this->put(self::KEY_LOGO_PATH, '');

        $this->activity->record(
            $actor,
            'settings.logo_removed',
            null,
            'Platform logo removed.',
        );

        return $this->allForAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function storeBkashQr(User $actor, UploadedFile $file): array
    {
        $path = $file->store('bkash-qr', 'public');
        $previous = $this->get(self::KEY_BKASH_QR_PATH);

        $this->put(self::KEY_BKASH_QR_PATH, $path);

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        $this->activity->record(
            $actor,
            'settings.bkash_qr_updated',
            null,
            'bKash QR image updated.',
        );

        return $this->allForAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteBkashQr(User $actor): array
    {
        $previous = $this->get(self::KEY_BKASH_QR_PATH);

        if (is_string($previous) && $previous !== '') {
            Storage::disk('public')->delete($previous);
        }

        $this->put(self::KEY_BKASH_QR_PATH, '');

        $this->activity->record(
            $actor,
            'settings.bkash_qr_removed',
            null,
            'bKash QR image removed.',
        );

        return $this->allForAdmin();
    }

    private function put(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    private function logoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * @return array<string, array{configured: bool, sandbox: bool}>
     */
    private function paymentGatewayStatus(): array
    {
        $ssl = config('services.sslcommerz', []);
        $bkash = config('services.bkash', []);

        return [
            'sslcommerz' => [
                'configured' => filled($ssl['store_id'] ?? null) && filled($ssl['store_password'] ?? null),
                'sandbox' => (bool) ($ssl['sandbox'] ?? true),
            ],
            'bkash' => [
                'configured' => filled($bkash['username'] ?? null)
                    && filled($bkash['password'] ?? null)
                    && filled($bkash['app_key'] ?? null)
                    && filled($bkash['app_secret'] ?? null),
                'sandbox' => (bool) ($bkash['sandbox'] ?? true),
            ],
        ];
    }

    /**
     * Locked product decisions — not editable in v1.
     *
     * @return array<string, mixed>
     */
    private function courseAccessRules(): array
    {
        return [
            'purchase_model' => 'one_time',
            'access_duration' => 'lifetime',
            'multiple_courses_allowed' => true,
            'one_active_enrollment_per_course' => true,
            'refunds' => 'manual_admin_only',
            'editable' => false,
        ];
    }
}
