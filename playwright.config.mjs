import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    timeout: 45_000,
    forbidOnly: Boolean(process.env.CI),
    use: {
        ...devices['Desktop Chrome'],
        actionTimeout: 7_000,
        trace: 'retain-on-failure',
    },
});
