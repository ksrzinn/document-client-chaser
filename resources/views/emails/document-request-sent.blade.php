<!DOCTYPE html>
<html>
<body>
    <p>Hi {{ $clientName }},</p>

    <p>{{ $businessName }} has requested documents from you.</p>

    @if ($requestMessage)
        <p>{{ $requestMessage }}</p>
    @endif

    @if ($dueAt)
        <p>Please upload the requested documents by <strong>{{ $dueAt }}</strong>.</p>
    @endif

    <p>
        <a href="{{ $link }}">Upload your documents</a>
    </p>

    <p>If the link above does not work, copy and paste this URL into your browser:</p>
    <p>{{ $link }}</p>
</body>
</html>
