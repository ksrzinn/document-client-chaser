<!DOCTYPE html>
<html>
<body>
    <p>Hi {{ $clientName }},</p>

    <p>This is a reminder from {{ $businessName }} — some requested documents are still missing.</p>

    @if ($requestMessage)
        <p>{{ $requestMessage }}</p>
    @endif

    <p>Still missing:</p>
    <ul>
        @foreach ($missingItemNames as $name)
            <li>{{ $name }}</li>
        @endforeach
    </ul>

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
