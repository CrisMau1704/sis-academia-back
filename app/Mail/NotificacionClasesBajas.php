<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Estudiante;
use App\Models\Inscripcion;

class NotificacionClasesBajas extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $estudiante;
    public $inscripcion;
    public $datos;

    /**
     * Create a new message instance.
     */
    public function __construct(Estudiante $estudiante, Inscripcion $inscripcion, array $datos = [])
    {
        $this->estudiante = $estudiante;
        $this->inscripcion = $inscripcion;
        $this->datos = $datos;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $asunto = $this->datos['asunto'] ?? 'Notificación de Clases Restantes';
        
        return new Envelope(
            subject: $asunto,
            from: env('MAIL_FROM_ADDRESS', 'controlcalidad@infoactiva.com.bo'),
            replyTo: env('MAIL_FROM_ADDRESS', 'controlcalidad@infoactiva.com.bo'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.clases_bajas',
            with: [
                'estudiante' => $this->estudiante,
                'inscripcion' => $this->inscripcion,
                'datos' => $this->datos,
                'porcentaje' => $this->inscripcion->clases_totales > 0 
                    ? round(($this->inscripcion->clases_asistidas / $this->inscripcion->clases_totales) * 100, 1)
                    : 0,
                'fecha_actual' => now()->format('d/m/Y H:i'),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}