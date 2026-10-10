<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DraftInvitation extends Mailable
{
    public function __construct(public Team $team)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->team->draft->name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.draft-invitation',
            with: [
                'draftName' => $this->team->draft->name,
                'hostName' => $this->team->draft->creator?->username,
                'url' => route('join.draft.form', ['token' => $this->team->token]),
            ],
        );
    }
}
