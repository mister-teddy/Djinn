const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve(__dirname, 'app/admin/index.tsx'),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			'@shared': path.resolve(__dirname, 'app/shared'),
			'@gql': path.resolve(__dirname, 'app/gql'),
		},
	},
};
