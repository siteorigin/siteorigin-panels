// Shared SiteOrigin Playwright config: testDir ./tests/e2e, global setup and
// the cached admin storage state. See tests/scripts/run-e2e.js.
const config = require( 'siteorigin-tests-common/playwright/config' );

// These specs share one pre-write listener whose mode, call log and render
// count are site-wide options (tests/playground/mu-plugins/panels-e2e.php).
// Run them one at a time, so one spec's mode or log never reaches another.
// The other specs still run in parallel with them on CI.
const LISTENER_SPECS = [
	'**/abilities-mcp.test.js',
	'**/layout-update-pre-write.test.js',
	'**/layout-update-structure.test.js',
];

const [ chrome ] = config.projects;

module.exports = {
	...config,
	projects: [
		{
			...chrome,
			testIgnore: LISTENER_SPECS,
		},
		{
			...chrome,
			name: `${ chrome.name } (listener)`,
			testMatch: LISTENER_SPECS,
			workers: 1,
		},
	],
};
