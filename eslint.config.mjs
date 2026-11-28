import js from '@eslint/js';
import globals from 'globals';

export default [
	{
		ignores: [ 'public/lib/**', 'vendor/**', 'node_modules/**', 'build/**', 'tests/**' ],
	},
	{
		files: [ 'public/js/**/*.js', 'admin/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: globals.browser,
		},
		rules: {
			...js.configs.recommended.rules,
			'no-unused-vars': [ 'error', { caughtErrors: 'none' } ],
			strict: [ 'error', 'function' ],
			eqeqeq: [ 'error', 'always' ],
			curly: [ 'error', 'all' ],
			'no-implicit-globals': 'error',
			'no-console': 'error',
			'no-alert': 'error',
			'no-eval': 'error',
			'no-implied-eval': 'error',
			'no-new-func': 'error',
		},
	},
];
