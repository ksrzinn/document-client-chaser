<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Link unavailable</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* Token values duplicated from resources/css/app.css :root — this page
           intentionally has no @@vite/build dependency, since it is an error
           path that must render even if the asset pipeline is broken. */
        body {
            margin: 0;
            padding: 2rem;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f2f2f3;
            color: #1d1f20;
            font-family: Barlow, system-ui, sans-serif;
        }
        .card {
            max-width: 26rem;
            width: 100%;
            text-align: center;
            background: #fff;
            border: 1px solid color-mix(in srgb, #1d1f20 16%, transparent);
            border-radius: 8px;
            padding: 2rem 1.75rem;
        }
        .icon {
            display: grid;
            place-items: center;
            width: 52px;
            height: 52px;
            margin: 0 auto 0.75rem;
            border-radius: 8px;
            background: #f7efe1;
            color: #7a5312;
        }
        h1 {
            font-family: "Barlow Condensed", system-ui, sans-serif;
            font-weight: 600;
            font-size: 1.375rem;
            margin: 0 0 0.5rem;
        }
        p {
            margin: 0;
            color: #5d5d60;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5l3 2"></path></svg>
        </span>
        <h1>This link isn't available</h1>
        <p>It may have expired or been withdrawn. Please contact the person who sent it to you.</p>
    </div>
</body>
</html>
