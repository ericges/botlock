<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Botlock - Verified</title>
    <style>
        :root {
            --color-text-primary: #333;
            --color-text-secondary: #555;
            --color-text-heading: #2c3e50;
            --color-background-body: #f4f7f6;
            --color-background-container: #ffffff;
            --color-accent: #4CAF50;
            --color-shadow: rgba(0, 0, 0, 0.1);
            --color-button: #eaeaea;
            --color-button-hover: #dfdfdf;
            --color-button-text: #444;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --color-text-primary: #e0e0e0;
                --color-text-secondary: #b0b0b0;
                --color-text-heading: #ffffff;
                --color-background-body: #121212;
                --color-background-container: #1e1e1e;
                --color-shadow: rgba(0, 0, 0, 0.4);
                --color-button: #333;
                --color-button-hover: #444;
                --color-button-text: #e0e0e0;
            }
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            line-height: 1.6;
            color: var(--color-text-primary);
            background-color: var(--color-background-body);
            margin: 0;
            padding: .5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .container {
            --border-width: 5px;
            background-color: var(--color-background-container);
            padding-inline: 1.5rem;
            padding-block: 3rem 2.75rem;
            border-radius: 8px;
            box-shadow: 0 .5rem 1rem var(--color-shadow);
            text-align: center;
            max-width: 65ch;
            width: 100%;
            border-top: var(--border-width) solid var(--color-accent);
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }

        h1 {
            color: var(--color-text-heading);
            margin-block: 0 2rem;
            font-size: 2.2em;
            font-weight: 600;
        }

        p {
            color: var(--color-text-secondary);
            font-size: 1.1em;
            margin-bottom: 15px;
        }

        .success-message {
            color: var(--color-accent);
            font-weight: 500;
            margin-bottom: 2rem;
        }

        button {
            background-color: var(--color-button);
            color: var(--color-button-text);
            border: none;
            padding: 0.8rem 1.5rem;
            font-size: 1em;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.22s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        button:hover {
            background-color: var(--color-button-hover);
        }

        @media (max-width: 600px) {
            .container {
                padding: 2rem 1rem;
            }
            h1 {
                font-size: 1.8em;
            }
            p {
                font-size: 1em;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Verification Complete!</h1>
    <p>This page is protected by Botlock security measures.</p>
    <p class="success-message">Your browser has successfully passed the challenge.</p>
    <form method="post" action="?_botlock=reset">
        <input type="hidden" name="location" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
        <button type="submit">Reset Botlock Challenge</button>
    </form>
</div>

</body>
</html>