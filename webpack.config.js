const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		lamp: path.resolve( __dirname, 'app/lamp/index.tsx' ),
		cave: path.resolve( __dirname, 'app/cave/index.tsx' ),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			'@shared': path.resolve( __dirname, 'app/shared' ),
			'@gql': path.resolve( __dirname, 'app/gql' ),
		},
	},
};
