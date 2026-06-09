import { createElement as h } from '@wordpress/element';
import type { ReactNode } from 'react';

// Tiny, dependency-free, XSS-safe syntax highlighters: each tokenizes source into coloured <span>s
// (no innerHTML, like markdown.tsx). Tuned for the Djinn code surface (dark) — gold for the names
// you scan for, soft greens/violets for literals, muted ivory for punctuation.

const TONE = {
	punct: 'text-ivory-muted/70',
	keyword: 'text-[#c4b5fd]', // graphql query/mutation/fragment/on
	name: 'text-gold', // field & argument names, json keys
	string: 'text-[#86efac]',
	number: 'text-[#fcd34d]',
	literal: 'text-[#f0abfc]', // true/false/null
	comment: 'text-ivory-muted/50',
	var: 'text-[#7dd3fc]', // $variables
};

function span(cls: string, text: string, key: number): ReactNode {
	return h('span', { key, className: cls }, text);
}

const GQL_KEYWORDS = new Set([
	'query',
	'mutation',
	'subscription',
	'fragment',
	'on',
	'true',
	'false',
	'null',
]);

// One pass: comments, strings, $variables, names, and punctuation. Names are coloured as keywords
// when reserved, else as field/arg names — close enough for a read-only preview without a real lexer.
export function highlightGraphql(src: string): ReactNode[] {
	const re =
		/(#[^\n]*)|("(?:\\.|[^"\\])*")|(\$[A-Za-z_]\w*)|([A-Za-z_]\w*)|(\.\.\.)|([{}()\[\]:,!=|&@])/g;
	const out: ReactNode[] = [];
	let last = 0;
	let m: RegExpExecArray | null;
	let k = 0;
	while ((m = re.exec(src)) !== null) {
		if (m.index > last) {
			out.push(src.slice(last, m.index));
		}
		const t = m[0];
		if (m[1]) {
			out.push(span(TONE.comment, t, k++));
		} else if (m[2]) {
			out.push(span(TONE.string, t, k++));
		} else if (m[3]) {
			out.push(span(TONE.var, t, k++));
		} else if (m[4]) {
			const lower = t.toLowerCase();
			out.push(
				span(
					GQL_KEYWORDS.has(lower)
						? lower === 'true' ||
							lower === 'false' ||
							lower === 'null'
							? TONE.literal
							: TONE.keyword
						: TONE.name,
					t,
					k++,
				),
			);
		} else {
			out.push(span(TONE.punct, t, k++));
		}
		last = re.lastIndex;
	}
	if (last < src.length) {
		out.push(src.slice(last));
	}
	return out;
}

// Pretty-print a value, then colour keys / strings / numbers / literals / punctuation. A quoted run
// immediately followed by a colon is a key (gold); any other quoted run is a string value.
export function highlightJson(value: unknown): ReactNode[] {
	let src: string;
	try {
		src = JSON.stringify(value, null, 2);
	} catch {
		src = String(value);
	}
	if (src === undefined) {
		src = 'null';
	}
	return highlightJsonString(src);
}

export function highlightJsonString(src: string): ReactNode[] {
	const re =
		/("(?:\\.|[^"\\])*")(\s*:)?|(\b(?:true|false|null)\b)|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|([{}\[\],:])/g;
	const out: ReactNode[] = [];
	let last = 0;
	let m: RegExpExecArray | null;
	let k = 0;
	while ((m = re.exec(src)) !== null) {
		if (m.index > last) {
			out.push(src.slice(last, m.index));
		}
		if (m[1]) {
			// Quoted run; the optional m[2] is a trailing colon → it's a key.
			out.push(span(m[2] ? TONE.name : TONE.string, m[1], k++));
			if (m[2]) {
				out.push(span(TONE.punct, m[2], k++));
			}
		} else if (m[3]) {
			out.push(span(TONE.literal, m[3], k++));
		} else if (m[4]) {
			out.push(span(TONE.number, m[4], k++));
		} else {
			out.push(span(TONE.punct, m[5], k++));
		}
		last = re.lastIndex;
	}
	if (last < src.length) {
		out.push(src.slice(last));
	}
	return out;
}
