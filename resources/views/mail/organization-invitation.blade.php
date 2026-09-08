@component('mail::message')
# You're invited!

{{ $inviterName }} has invited you to join **{{ $organizationName }}** on {{ config('app.name') }} as a **{{ $role }}**.

@component('mail::button', ['url' => $acceptUrl])
Accept Invitation
@endcomponent

If you weren't expecting this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
