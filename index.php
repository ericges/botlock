<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Botlock Document - Verified</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            line-height: 1.6;
            color: #333;
            background-color: #f4f7f6; /* Light grey-green background */
            margin: 0;
            padding: 20px;
            display: flex; /* Use flexbox for centering */
            justify-content: center; /* Center horizontally */
            align-items: center; /* Center vertically */
            min-height: 100vh; /* Ensure body takes full viewport height */
        }

        /* Content Container */
        .container {
            background-color: #ffffff; /* White background for content */
            padding: 40px 50px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); /* Subtle shadow */
            text-align: center;
            max-width: 600px; /* Limit maximum width */
            width: 90%; /* Responsive width */
            border-top: 5px solid #4CAF50; /* Green accent top border (suggests success) */
        }

        /* Heading Style */
        h1 {
            color: #2c3e50; /* Dark blue-grey heading */
            margin-bottom: 25px;
            font-size: 2.2em; /* Larger heading */
            font-weight: 600;
        }

        /* Paragraph Style */
        p {
            color: #555; /* Slightly lighter text for paragraphs */
            font-size: 1.1em;
            margin-bottom: 15px;
        }

        /* Style for the success message */
        .success-message {
            color: #4CAF50; /* Green text for success */
            font-weight: 500;
        }

        /* Small device adjustments */
        @media (max-width: 600px) {
            .container {
                padding: 30px 25px;
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
</div>

</body>
</html>