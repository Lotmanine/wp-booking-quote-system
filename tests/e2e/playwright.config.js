import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	testDir: './specs',
	timeout: 300000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	globalSetup: './global-setup.js',
	use: {
		baseURL: process.env.BASE_URL || 'http://localhost:18091',
		actionTimeout: 30000,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'desktop', use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1280, height: 900 } } },
		{ name: 'phone', use: { ...devices[ 'Pixel 7' ] } },
	],
} );
