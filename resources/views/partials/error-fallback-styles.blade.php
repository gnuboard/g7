<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    .error-container {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        background-color: #f9fafb;
        padding: 1rem;
    }
    .error-card {
        max-width: 28rem;
        width: 100%;
        background-color: #ffffff;
        border-radius: 0.5rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        padding: 2rem;
        text-align: center;
    }
    .error-icon {
        margin-bottom: 1.5rem;
    }
    .error-icon i {
        font-size: 4rem;
        color: #ef4444;
    }
    .error-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 1rem;
    }
    .error-message {
        color: #4b5563;
        margin-bottom: 1.5rem;
        line-height: 1.625;
    }
    @media (prefers-color-scheme: dark) {
        .error-container {
            background-color: #111827;
        }
        .error-card {
            background-color: #1f2937;
        }
        .error-title {
            color: #ffffff;
        }
        .error-message {
            color: #d1d5db;
        }
    }
</style>
