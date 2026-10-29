<?php

namespace App\Notifications;

use App\Library\ExportStorage;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a reader their data export is ready, with the one link that downloads it. The link is signed, expires after `bookflix.exports.link_hours`
 * and only works for the signed-in owner, so a forwarded email gives nothing to anyone else. Sent by mail only: the notification is the way the
 * link reaches the reader, and the privacy page shows the same state.
 */
class DataExportReady extends Notification
{
    public function __construct(public readonly string $file) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $hours = (int) config('bookflix.exports.link_hours');

        return (new MailMessage)
            ->subject(__('Your Bookflix data export is ready'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The export of your Bookflix data is ready: your profile, library, collections, ratings, reading progress, history and activity, as JSON files in one zip.'))
            ->action(__('Download your data'), app(ExportStorage::class)->link($notifiable->id, $this->file))
            ->line(__('The link works for :hours hours, and only while you are signed in to this account. The file is deleted after :days days.', ['hours' => $hours, 'days' => (int) config('bookflix.exports.retention_days')]))
            ->line(__('If you did not ask for this, you can ignore this email, or change your password.'));
    }
}
