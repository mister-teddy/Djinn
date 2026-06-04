/** @type {import('tailwindcss').Config} */
module.exports = {
	content: [ './app/**/*.{ts,tsx}' ],
	// The SPA lives inside wp-admin's large body of unlayered global CSS. Scoping every utility under
	// the mount class (rather than `important: true`) wins the cascade without `!important` spam.
	important: '.djinn-app',
	corePlugins: { preflight: false },
	theme: {
		extend: {
			colors: {
				midnight: { DEFAULT: 'var(--djinn-midnight)', 2: 'var(--djinn-midnight-2)' },
				violet: { DEFAULT: 'var(--djinn-violet)', soft: 'var(--djinn-violet-soft)' },
				ivory: { DEFAULT: 'var(--djinn-ivory)', muted: 'var(--djinn-ivory-muted)' },
				gold: { DEFAULT: 'var(--djinn-gold)', deep: 'var(--djinn-gold-deep)', ember: 'var(--djinn-gold-ember)' },
				line: 'var(--djinn-line)',
				divider: 'var(--djinn-divider)',
			},
			borderRadius: { djinn: 'var(--djinn-radius)', control: 'var(--djinn-control-radius)' },
			boxShadow: { glow: 'var(--djinn-glow)' },
			fontFamily: { serif: [ 'Cardo', 'serif' ] },
		},
	},
	plugins: [],
};
