<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:16px;max-width:560px;border:1px solid #e5e9ef;border-collapse:collapse;background:#ffffff;">
    <tr>
        <td width="168" valign="middle" style="width:168px;padding:20px 12px 20px 20px;border:0;">
            <a href="{{ $shareUrl }}" target="_blank" title="Zur Freigabe" style="display:block;text-decoration:none;border:0;outline:none;">
                <img src="{{ $logoUrl }}" alt="Cloud Share" width="148" style="display:block;border:0;outline:none;text-decoration:none;width:148px;height:auto;">
            </a>
        </td>
        <td valign="middle" style="padding:20px 20px 20px 8px;border:0;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.5;color:#323130;">
            <div style="margin:0 0 10px 0;">
                <strong style="display:block;font-size:11px;color:#5a5a5a;text-transform:uppercase;letter-spacing:0.04em;">Freigabe</strong>
                {{ $shareName !== '' ? $shareName : 'Cloud-Ordner' }}
            </div>
            <div style="margin:0 0 4px 0;">Passwortschutz: {{ $passwordProtectionLabel }}</div>
            <div style="margin:0 0 4px 0;">Gültig bis: {{ $expirationLabel }}</div>
            <div style="margin:0 0 16px 0;">Gast-Upload: {{ $guestUploadLabel }}</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
                <tr>
                    <td align="center" bgcolor="#0078d4" style="background:#0078d4;border-radius:8px;mso-padding-alt:12px 28px;">
                        <a href="{{ $shareUrl }}" target="_blank" style="display:inline-block;padding:12px 28px;font-family:'Segoe UI',Arial,sans-serif;font-size:14px;font-weight:600;line-height:1.2;letter-spacing:0.02em;color:#ffffff;text-decoration:none;border-radius:8px;">
                            Zur Freigabe
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
