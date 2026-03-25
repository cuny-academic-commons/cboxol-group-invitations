/**
 * Custom webpack configuration extending @wordpress/scripts defaults.
 *
 * - Entry:  assets/src/index.js
 * - Output: build/
 *
 * Run via:
 *   npm run build   (production)
 *   npm start       (development / watch)
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'assets/src/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
