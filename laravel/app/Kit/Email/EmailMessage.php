<?php

namespace App\Kit\Email;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * One email as a driver sends it: who it is from and to, its subject, and its HTML and plain-text bodies. Made by
 * Emails from a rendered template; read by the drivers.
 */
final class EmailMessage
{
    public function __construct(
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
    ) {}

    /** The same message as Symfony Mime builds it (for drivers that send through Symfony Mailer). */
    public function toMime(): Email
    {
        return (new Email)
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($this->to)
            ->subject($this->subject)
            ->html($this->html)
            ->text($this->text);
    }
}
