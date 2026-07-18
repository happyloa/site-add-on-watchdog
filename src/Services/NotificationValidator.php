<?php

namespace Watchdog\Services;

/**
 * Validates enabled channel settings before they are persisted or dispatched.
 */
final class NotificationValidator
{
    /**
     * @param array<string, mixed> $notifications
     * @return array<string, string>
     */
    public function validate(array $notifications): array
    {
        $errors = [];

        $email = $this->channelSettings($notifications, 'email');
        if (! empty($email['enabled'])) {
            $recipients = isset($email['recipients']) ? trim((string) $email['recipients']) : '';
            if ($recipients !== '' && $this->validEmails($recipients) === []) {
                $errors['email'] = __(
                    'Email notifications were disabled because no valid recipient address was provided.',
                    'site-add-on-watchdog'
                );
            }
        }

        $urlChannels = [
            'discord' => [
                'field' => 'webhook',
                'error' => 'discord',
            ],
            'slack' => [
                'field' => 'webhook',
                'error' => 'slack',
            ],
            'teams' => [
                'field' => 'webhook',
                'error' => 'teams',
            ],
            'webhook' => [
                'field' => 'url',
                'error' => 'webhook',
            ],
        ];

        foreach ($urlChannels as $channel => $definition) {
            $settings = $this->channelSettings($notifications, $channel);
            if (empty($settings['enabled'])) {
                continue;
            }

            $url = isset($settings[$definition['field']]) ? trim((string) $settings[$definition['field']]) : '';
            if ($this->isValidHttpsUrl($url)) {
                continue;
            }

            $errors[$channel] = $this->urlErrorMessage($definition['error']);
        }

        return $errors;
    }

    public function isValidHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    /**
     * @return string[]
     */
    private function validEmails(string $recipients): array
    {
        $valid = [];

        foreach (preg_split('/[,;\s]+/', $recipients) ?: [] as $recipient) {
            $email = trim($recipient);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = strtolower($email);
            }
        }

        return array_values(array_unique($valid));
    }

    private function urlErrorMessage(string $channel): string
    {
        return match ($channel) {
            'discord' => __(
                'Discord notifications were disabled because a valid HTTPS webhook URL is required.',
                'site-add-on-watchdog'
            ),
            'slack' => __(
                'Slack notifications were disabled because a valid HTTPS webhook URL is required.',
                'site-add-on-watchdog'
            ),
            'teams' => __(
                'Microsoft Teams notifications were disabled because a valid HTTPS webhook URL is required.',
                'site-add-on-watchdog'
            ),
            default => __(
                'Custom webhook notifications were disabled because a valid HTTPS webhook URL is required.',
                'site-add-on-watchdog'
            ),
        };
    }

    /**
     * @param array<string, mixed> $notifications
     * @return array<string, mixed>
     */
    private function channelSettings(array $notifications, string $channel): array
    {
        $settings = $notifications[$channel] ?? [];

        return is_array($settings) ? $settings : [];
    }
}
