module.exports = {
    slug: 'siteorigin-panels',
    jsMinSuffix: '.min',
    version: {
        src: [
            'siteorigin-panels.php',
            'readme.txt'
        ]
    },
    less: {
        src: [
            'css/**/*.less',
            '!css/mixins.less',
            'settings/**/*.less',
            '!widgets/**/styles/*.less',
            '!widgets/less/*.less'
        ],
        include: [
            'widgets/less'
        ]
    },
    sass: {
        src: [],
        include:[]
    },
    js: {
        src: [
            'compat/**/*.js',
            'js/**/*.js',
            'settings/**/*.js',
            'widgets/**/*.js',
            '!js/siteorigin-panels/**',   // Ignore the SiteOrigin Panels JS, they're handled by Browserify
            '!{tmp,tmp/**}'               // Ignore tmp/ and contents
        ]
    },
    babel: {
        src: [
            'compat/**/*.jsx',
        ],
    },
    browserify : {
        src: 'js/siteorigin-panels/main.js',
        dest: 'js/',
        fileName: 'siteorigin-panels.js',
        watchFiles: [
            'js/siteorigin-panels/**',
        ]
    },
	css: {
		src: [
			'css/**/*.css',
			'!css/**/*.min.css',   // Don't re-minify already-built .min.css into .min.min.css
		],
	},
	bust : {
		src: [
			'js/siteorigin-panels.js',
			'js/styling.js',
		]
	},
    copy: {
        src: [
            '**/!(*.js|*.jsx|*.less)',                       // Everything except .js/.jsx and .less files
            '!{build,build/**}',                             // Ignore build/ and contents
            'widgets/less/*.less',                           // LESS libraries used in runtime styles
            'widgets/**/styles/*.less',                      // All the widgets' runtime .less files
            'inc/installer/js/*js',                          // Include Installer JS.
            '!widgets/**/styles/*.css',                      // Don't copy any .css files compiled from runtime .less files
            '!{node_modules,node_modules/**}',               // Ignore node_modules/ and contents
            '!{docs,docs/**}',                               // Ignore docs/ (internal plans/research, never shipped)
            '!{tests,tests/**}',                             // Ignore tests/ (PHPUnit suites and the render harness)
            '!{tasks,tasks/**}',                             // Ignore tasks/ (local run records)
            '!phpunit*.xml',                                 // Ignore PHPUnit suite configs
            '!composer.json',                                // Ignore composer manifest (dev dependencies only)
            '!composer.lock',                                // Ignore composer lockfile
            '!{tmp,tmp/**}',                                 // Ignore dist/ and contents
            '!{vendor,vendor/**}',                           // Ignore composer files. We're not using any in release.
            '!siteorigin-panels.php',                        // Not the base plugin file. It is copied by the 'version' task.
            '!package.json',                                 // Ignore the package.json file..
            '!package-lock.json',                            // ..and the lockfile for the JS unit tests.
            '!readme.txt',                                   // Not the readme.txt file. It is copied by the 'version' task.
            '!readme.md',                                    // Ignore the readme.md file. It is for the github repo.
            '!{js/siteorigin-panels,js/siteorigin-panels/**}',
            '!{.wordpress-org,.wordpress-org/**}',           // Exclude WordPress.org specific code.
            '!{inc/installer/github-updater,inc/installer/github-updater/**}', // Exclude Installer GitHub Updater directory.
        ]
    },
    i18n: {
        src: [
            '**/*.php',                         // All the PHP files.
            '!tmp/**/*.php',                    // Ignore tmp/ and contents
            '!dist/**/*.php',                   // Ignore dist/ and contents
            '!tests/**/*.php',                  // Ignore tests/ — no translatable strings, and modern PHP syntax breaks gulp-wp-pot's bundled php-parser.
            '!vendor/**/*.php',                 // Composer deps: modern PHP breaks gulp-wp-pot's php-parser.
            '!node_modules/**/*.php'            // Never scan node deps for translatable strings.
        ],
    },
};
