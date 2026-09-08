<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly OrganizationInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':org invited you to join FireFighters Chat', ['org' => $this->invitation->organization->name]),
        );
    }

    public function content(): Content
    {
        $inviter = $this->invitation->invitedBy()->first();

        return new Content(
            markdown: 'mail.organization-invitation',
            with: [
                'organizationName' => $this->invitation->organization->name,
                'inviterName' => $inviter !== null ? $inviter->name : __('A team member'),
                'role' => $this->invitation->role->value,
                'acceptUrl' => route('invitations.accept', $this->invitation->token),
            ],
        );
    }
}
