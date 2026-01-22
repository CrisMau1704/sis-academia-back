<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notificación de Clases Restantes - Academia King Boxing</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 25px;
            border-radius: 10px 10px 0 0;
            text-align: center;
            margin: -30px -30px 30px -30px;
        }
        .header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 600;
        }
        .header p {
            margin: 5px 0 0;
            opacity: 0.9;
        }
        .alert-box {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
            border-left: 6px solid #f59e0b;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
        }
        .alert-box.critico {
            background-color: #fee2e2;
            border-color: #fecaca;
            border-left-color: #ef4444;
        }
        .alert-box h3 {
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .student-info {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background-color: #f8fafc;
            border-radius: 8px;
            margin: 20px 0;
        }
        .avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            flex-shrink: 0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 25px 0;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            display: block;
            color: #1f2937;
        }
        .stat-label {
            font-size: 14px;
            color: #6b7280;
            margin-top: 8px;
        }
        .progress-container {
            margin: 25px 0;
        }
        .progress-bar {
            height: 10px;
            background-color: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 5px;
            transition: width 0.3s ease;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 25px 0;
            text-align: center;
        }
        .details {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .details h3 {
            margin-top: 0;
            color: #0369a1;
        }
        .contact-info {
            background-color: #faf5ff;
            border: 1px solid #e9d5ff;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .contact-info h3 {
            margin-top: 0;
            color: #7c3aed;
        }
        .action-buttons {
            text-align: center;
            margin: 30px 0;
        }
        .btn-renew {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 10px;
        }
        .btn-contact {
            display: inline-block;
            padding: 14px 32px;
            background: #f8fafc;
            color: #3b82f6;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            border: 2px solid #3b82f6;
            margin: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏆 Academia King Boxing</h1>
            <p>Sistema de Gestión de Clases</p>
        </div>

        <h2>Hola {{ $estudiante->nombres }} {{ $estudiante->apellidos }},</h2>
        
        <div class="alert-box {{ $datos['nivel'] === 'critico' ? 'critico' : '' }}">
            <h3>
                @if($datos['nivel'] === 'critico')
                ⚠️ ¡ATENCIÓN! - CLASES CRÍTICAS
                @elseif($datos['nivel'] === 'advertencia')
                🔔 ADVERTENCIA - CLASES POR AGOTARSE
                @else
                ℹ️ INFORMACIÓN DE CLASES
                @endif
            </h3>
            <p style="margin-bottom: 0; font-weight: 500; line-height: 1.8;">
                @if($datos['nivel'] === 'critico')
                Solo te quedan <strong style="color: #dc2626;">{{ $datos['clases_restantes'] ?? 0 }} clase(s)</strong> disponibles.
                <br><br>Por favor, considera <strong>renovar tu inscripción</strong> para continuar con tus entrenamientos sin interrupciones.
                @elseif($datos['nivel'] === 'advertencia')
                Te quedan <strong style="color: #d97706;">{{ $datos['clases_restantes'] ?? 0 }} clase(s)</strong> restantes.
                <br><br>Planifica tu <strong>renovación con anticipación</strong> para garantizar tu continuidad.
                @else
                Tienes <strong>{{ $datos['clases_restantes'] ?? 0 }} clase(s)</strong> restantes de tu inscripción actual.
                @endif
            </p>
        </div>

        <div class="student-info">
            <div class="avatar">
                @php
                    $iniciales = substr($estudiante->nombres, 0, 1) . substr($estudiante->apellidos, 0, 1);
                    echo strtoupper($iniciales);
                @endphp
            </div>
            <div>
                <h3 style="margin: 0;">{{ $estudiante->nombres }} {{ $estudiante->apellidos }}</h3>
                <p style="margin: 5px 0; color: #6b7280;">
                    CI: {{ $estudiante->ci ?? 'No registrado' }}<br>
                    Modalidad: {{ $inscripcion->modalidad->nombre ?? 'Sin modalidad' }}
                </p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value">{{ $inscripcion->clases_asistidas ?? 0 }}</span>
                <span class="stat-label">Clases Asistidas</span>
            </div>
            <div class="stat-card">
                <span class="stat-value">{{ $datos['clases_restantes'] ?? 0 }}</span>
                <span class="stat-label">Clases Restantes</span>
            </div>
            <div class="stat-card">
                <span class="stat-value">{{ $inscripcion->clases_totales ?? 0 }}</span>
                <span class="stat-label">Clases Totales</span>
            </div>
        </div>

        <div class="progress-container">
            <p><strong>Progreso de tu inscripción:</strong></p>
            <div class="progress-bar">
                <div class="progress-fill" style="width: {{ $porcentaje }}%;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 14px; color: #6b7280;">
                <span>{{ $inscripcion->clases_asistidas ?? 0 }} asistidas</span>
                <span>{{ $porcentaje }}% completado</span>
            </div>
        </div>

        @if($datos['nivel'] === 'critico' || $datos['nivel'] === 'advertencia')
        <div class="action-buttons">
            <p><strong>Acciones recomendadas:</strong></p>
            <a href="#" class="btn-renew">📅 Renovar Inscripción</a>
            <a href="https://wa.me/591XXXXXXXXX?text=Hola,%20quiero%20renovar%20mi%20inscripción" 
               class="btn-contact" 
               target="_blank">
               📞 Contactar Administración
            </a>
        </div>
        @endif

        <div class="details">
            <h3>📋 Detalles de tu inscripción</h3>
            <ul style="margin: 0; padding-left: 20px;">
                <li><strong>Fecha inicio:</strong> {{ \Carbon\Carbon::parse($inscripcion->fecha_inicio)->format('d/m/Y') }}</li>
                <li><strong>Fecha fin:</strong> {{ \Carbon\Carbon::parse($inscripcion->fecha_fin)->format('d/m/Y') }}</li>
                <li><strong>Estado:</strong> {{ ucfirst($inscripcion->estado) }}</li>
                <li><strong>Permisos restantes:</strong> {{ $inscripcion->permisos_disponibles ?? 0 }}</li>
            </ul>
        </div>

        <div class="contact-info">
            <h3>📞 Información de contacto</h3>
            <p style="margin: 10px 0;">
                <strong>Academia King Boxing</strong><br>
                📍 Dirección: [Tu dirección aquí]<br>
                📱 Teléfono: [Tu teléfono aquí]<br>
                📧 Email: [correo@academia.com]<br>
                ⏰ Horario: [Horario de atención]
            </p>
        </div>

        <div class="footer">
            <p>
                <em>Este es un mensaje automático del Sistema de Gestión de la Academia King Boxing.</em>
                <br>Fecha de envío: {{ $fecha_actual }}
            </p>
            <p style="font-size: 11px; color: #9ca3af;">
                Si crees que recibiste este correo por error o no deseas recibir más notificaciones,
                por favor contáctanos en [correo@academia.com].
            </p>
        </div>
    </div>
</body>
</html>