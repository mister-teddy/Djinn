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
				// Colors used with an opacity modifier (e.g. bg-gold/10) must be authored so Tailwind
				// can inject the alpha channel; a bare var() can't take one, so those vars hold RGB
				// channels and resolve through rgb(... / <alpha-value>). ivory DEFAULT stays a raw hex
				// (used directly in chrome.css) since it's never opacity-modified.
				ivory: {
					DEFAULT: 'var(--djinn-ivory)',
					muted: 'rgb(var(--djinn-ivory-muted) / <alpha-value>)',
				},
				gold: {
					DEFAULT: 'rgb(var(--djinn-gold) / <alpha-value>)',
					deep: 'rgb(var(--djinn-gold-deep) / <alpha-value>)',
					ember: 'rgb(var(--djinn-gold-ember) / <alpha-value>)',
				},
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
