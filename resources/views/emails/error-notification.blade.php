<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KHub Error Alert</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            margin: -30px -30px 20px -30px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .error-section {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .error-message {
            font-weight: bold;
            color: #dc2626;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 10px;
            margin: 20px 0;
        }
        .info-label {
            font-weight: bold;
            color: #666;
        }
        .info-value {
            color: #333;
            word-break: break-all;
        }
        .trace-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            overflow-x: auto;
        }
        .trace-section h3 {
            margin-top: 0;
            color: #666;
            font-size: 14px;
        }
        .trace-content {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #555;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-production {
            background: #dc2626;
            color: white;
        }
        .badge-staging {
            background: #f59e0b;
            color: white;
        }
        .badge-local {
            background: #10b981;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 KHub Error Alert</h1>
            <p>An error occurred in your application</p>
        </div>

        <div class="error-section">
            <div class="error-message">
                {{ $message }}
            </div>
        </div>

        <div class="info-grid">
            <div class="info-label">Environment:</div>
            <div class="info-value">
                @if($environment === 'production')
                    <span class="badge badge-production">PRODUCTION</span>
                @elseif($environment === 'staging')
                    <span class="badge badge-staging">STAGING</span>
                @else
                    <span class="badge badge-local">LOCAL</span>
                @endif
            </div>

            <div class="info-label">Timestamp:</div>
            <div class="info-value">{{ $timestamp }}</div>

            <div class="info-label">File:</div>
            <div class="info-value">{{ $file }}</div>

            <div class="info-label">Line:</div>
            <div class="info-value">{{ $line }}</div>

            <div class="info-label">URL:</div>
            <div class="info-value">{{ $url }}</div>

            <div class="info-label">Method:</div>
            <div class="info-value">{{ $method }}</div>

            <div class="info-label">IP Address:</div>
            <div class="info-value">{{ $ip }}</div>

            <div class="info-label">User ID:</div>
            <div class="info-value">{{ $user_id }}</div>
        </div>

        <div class="trace-section">
            <h3>Stack Trace:</h3>
            <div class="trace-content">{{ $trace }}</div>
        </div>

        <div class="footer">
            <p><strong>KHub - AI Knowledge Management</strong></p>
            <p>This is an automated error notification. Please check your application logs for more details.</p>
            <p style="margin-top: 10px;">
                <a href="{{ $url }}" style="color: #f97316; text-decoration: none;">View Error URL</a>
            </p>
        </div>
    </div>
</body>
</html>

