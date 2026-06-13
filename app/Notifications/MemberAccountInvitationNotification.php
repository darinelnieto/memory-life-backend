<?php

namespace App\Notifications;

use App\Models\Family;
use App\Models\TreeMember;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberAccountInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly Family $family,
        private readonly TreeMember $member,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendBase = rtrim((string) env('FRONTEND_URL', 'http://localhost:9000'), '/');
        $url = $frontendBase . '/#/auth/member-register?token=' . urlencode($this->token);

        return (new MailMessage())
            ->subject('Invitacion para crear tu cuenta en Memory Life')
            ->greeting('Hola ' . trim($this->member->first_name . ' ' . $this->member->last_name) . ',')
            ->line('Te invitaron a unirte a la familia ' . $this->family->surname . ' en Memory Life.')
            ->line('Crea tu usuario con este enlace para vincular tu perfil existente del arbol familiar.')
            ->action('Crear mi cuenta', $url)
            ->line('Si no solicitaste esto, puedes ignorar este correo.');
    }
}
