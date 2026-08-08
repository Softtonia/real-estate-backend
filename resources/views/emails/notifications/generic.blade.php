<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,sans-serif;color:#222;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7fb;padding:24px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
                <tr>
                    <td style="padding:24px;">
                        <h2 style="margin:0 0 12px;font-size:22px;line-height:1.35;">
                            {{ $title }}
                        </h2>

                        @if(! empty($userName))
                            <p style="margin:0 0 14px;font-size:15px;">
                                Hi {{ $userName }},
                            </p>
                        @endif

                        <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">
                            {!! nl2br(e($body)) !!}
                        </p>

                        @if(! empty($imageUrl))
                            <p style="margin:0 0 18px;">
                                <img src="{{ $imageUrl }}" alt="{{ $title }}" style="max-width:100%;border-radius:8px;">
                            </p>
                        @endif

                        @php
                            $url = $data['url'] ?? $data['link'] ?? $data['click_url'] ?? null;
                        @endphp

                        @if(! empty($url))
                            <p style="margin:20px 0;">
                                <a href="{{ $url }}" style="background:#ff5a1f;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:6px;display:inline-block;">
                                    Open
                                </a>
                            </p>
                        @endif

                        <p style="margin:24px 0 0;font-size:13px;color:#777;">
                            Regards,<br>
                            Holiplaces Team
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>