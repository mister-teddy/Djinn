import { createElement as h } from '@wordpress/element';
import type { ReactNode } from 'react';
import { safeUrl } from './url';
import { highlightGraphql, highlightJsonString } from './highlight';

// Markdown → React (no library, no innerHTML; XSS-safe by construction). Only http(s)/mailto/
// root-relative/anchor URLs survive; javascript:/data: are dropped.

const A = 'text-gold underline underline-offset-2 hover:text-gold-deep';
const CODE = 'rounded-[6px] bg-black/35 px-1.5 py-px font-mono text-[0.88em]';

function mdInline(text: string): ReactNode[] {
	const patterns: { re: RegExp; make: (m: RegExpExecArray) => ReactNode }[] =
		[
			{
				re: /`([^`]+)`/,
				make: (m) => h('code', { className: CODE }, m[1]),
			},
			{
				re: /!\[([^\]]*)\]\(([^)\s]+)\)/,
				make: (m) => {
					const u = safeUrl(m[2]);
					return u
						? h('img', {
								className:
									'my-1.5 h-auto max-w-full rounded-lg border border-white/10',
								src: u,
								alt: m[1],
								loading: 'lazy',
							})
						: m[1];
				},
			},
			{
				re: /\[([^\]]+)\]\(([^)\s]+)\)/,
				make: (m) => {
					const u = safeUrl(m[2]);
					return u
						? h(
								'a',
								{
									href: u,
									target: '_blank',
									rel: 'noopener noreferrer',
									className: A,
								},
								...mdInline(m[1]),
							)
						: m[0];
				},
			},
			{
				re: /\*\*([^*]+)\*\*/,
				make: (m) => h('strong', null, ...mdInline(m[1])),
			},
			{
				re: /__([^_]+)__/,
				make: (m) => h('strong', null, ...mdInline(m[1])),
			},
			{
				re: /\*([^*]+)\*/,
				make: (m) => h('em', null, ...mdInline(m[1])),
			},
			{ re: /_([^_]+)_/, make: (m) => h('em', null, ...mdInline(m[1])) },
			{
				re: /https?:\/\/[^\s<>()[\]'"]*[^\s<>()[\]'".,;:!?]/,
				make: (m) => {
					const u = safeUrl(m[0]);
					return u
						? h(
								'a',
								{
									href: u,
									target: '_blank',
									rel: 'noopener noreferrer',
									className: A,
								},
								m[0],
							)
						: m[0];
				},
			},
		];
	const out: ReactNode[] = [];
	let rest = String(text);
	while (rest) {
		let best: { p: (typeof patterns)[number]; m: RegExpExecArray } | null =
			null;
		for (const p of patterns) {
			const m = p.re.exec(rest);
			if (m && (!best || m.index < best.m.index)) {
				best = { p, m };
			}
		}
		if (!best) {
			out.push(rest);
			break;
		}
		if (best.m.index > 0) {
			out.push(rest.slice(0, best.m.index));
		}
		out.push(best.p.make(best.m));
		rest = rest.slice(best.m.index + best.m[0].length);
	}
	return out;
}

function mdTable(
	lines: string[],
	start: number,
): { next: number; node: ReactNode } {
	const cells = (l: string) =>
		l
			.replace(/^\s*\|/, '')
			.replace(/\|\s*$/, '')
			.split('|')
			.map((c) => c.trim());
	const head = cells(lines[start]);
	let i = start + 2;
	const rows: string[][] = [];
	while (
		i < lines.length &&
		lines[i].indexOf('|') !== -1 &&
		lines[i].trim()
	) {
		rows.push(cells(lines[i]));
		i++;
	}
	const th = 'bg-gold/[0.14] px-2.5 py-1.5 text-left font-semibold';
	const td = 'px-2.5 py-1.5 text-left';
	return {
		next: i,
		node: h(
			'table',
			{
				className:
					'my-2 w-full overflow-hidden rounded-[8px] border-collapse text-[13px]',
			},
			h(
				'thead',
				null,
				h(
					'tr',
					null,
					...head.map((c, j) =>
						h('th', { key: j, className: th }, ...mdInline(c)),
					),
				),
			),
			h(
				'tbody',
				null,
				...rows.map((r, ri) =>
					h(
						'tr',
						{
							key: ri,
							className: ri % 2 ? 'bg-white/[0.03]' : undefined,
						},
						...r.map((c, ci) =>
							h('td', { key: ci, className: td }, ...mdInline(c)),
						),
					),
				),
			),
		),
	};
}

const H_SIZE: Record<number, string> = {
	1: 'text-xl',
	2: 'text-lg',
	3: 'text-base',
	4: 'text-sm',
	5: 'text-sm',
	6: 'text-sm',
};

export function renderMarkdown(text: string): ReactNode[] {
	const lines = String(text || '')
		.replace(/\r\n?/g, '\n')
		.split('\n');
	const blocks: ReactNode[] = [];
	let i = 0;
	let key = 0;
	const isSpecial = (l: string) =>
		/^```/.test(l) ||
		/^(#{1,6})\s/.test(l) ||
		/^\s*>/.test(l) ||
		/^\s*[-*+]\s+/.test(l) ||
		/^\s*\d+\.\s+/.test(l);
	while (i < lines.length) {
		const line = lines[i];
		if (/^```/.test(line)) {
			const lang = (
				(/^```\s*([\w-]+)/.exec(line) || [])[1] || ''
			).toLowerCase();
			const buf: string[] = [];
			i++;
			while (i < lines.length && !/^```/.test(lines[i])) {
				buf.push(lines[i]);
				i++;
			}
			i++;
			const code = buf.join('\n');
			const body =
				lang === 'graphql' || lang === 'gql'
					? highlightGraphql(code)
					: lang === 'json'
						? highlightJsonString(code)
						: [code];
			blocks.push(
				h(
					'pre',
					{
						key: key++,
						className:
							'my-2 overflow-x-auto rounded-lg border border-white/5 bg-black/45 px-3.5 py-3',
					},
					h(
						'code',
						{
							className:
								'font-mono text-xs leading-relaxed text-[#e2e4e7] [white-space:pre]',
						},
						...body,
					),
				),
			);
			continue;
		}
		if (/^\s*$/.test(line)) {
			i++;
			continue;
		}
		const hd = /^(#{1,6})\s+(.*)$/.exec(line);
		if (hd) {
			const lvl = hd[1].length;
			blocks.push(
				h(
					`h${lvl}`,
					{
						key: key++,
						className: `mb-1.5 mt-3 font-semibold leading-snug text-ivory ${H_SIZE[lvl]}`,
					},
					...mdInline(hd[2]),
				),
			);
			i++;
			continue;
		}
		if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
			blocks.push(
				h('hr', {
					key: key++,
					className: 'my-3 border-0 border-t border-gold/20',
				}),
			);
			i++;
			continue;
		}
		if (/^\s*>/.test(line)) {
			const buf: string[] = [];
			while (i < lines.length && /^\s*>/.test(lines[i])) {
				buf.push(lines[i].replace(/^\s*>\s?/, ''));
				i++;
			}
			blocks.push(
				h(
					'blockquote',
					{
						key: key++,
						className:
							'my-2 border-l-[3px] border-gold/50 px-3 py-1 text-ivory-muted',
					},
					...mdInline(buf.join('\n')),
				),
			);
			continue;
		}
		if (
			line.indexOf('|') !== -1 &&
			i + 1 < lines.length &&
			/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/.test(lines[i + 1])
		) {
			const t = mdTable(lines, i);
			blocks.push(h('div', { key: key++ }, t.node));
			i = t.next;
			continue;
		}
		if (/^\s*[-*+]\s+/.test(line)) {
			const items: string[] = [];
			while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
				items.push(lines[i].replace(/^\s*[-*+]\s+/, ''));
				i++;
			}
			blocks.push(
				h(
					'ul',
					{ key: key++, className: 'my-1 mb-2 list-disc pl-[22px]' },
					...items.map((it, j) =>
						h(
							'li',
							{ key: j, className: 'my-0.5 leading-normal' },
							...mdInline(it),
						),
					),
				),
			);
			continue;
		}
		if (/^\s*\d+\.\s+/.test(line)) {
			const items: string[] = [];
			while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
				items.push(lines[i].replace(/^\s*\d+\.\s+/, ''));
				i++;
			}
			blocks.push(
				h(
					'ol',
					{
						key: key++,
						className: 'my-1 mb-2 list-decimal pl-[22px]',
					},
					...items.map((it, j) =>
						h(
							'li',
							{ key: j, className: 'my-0.5 leading-normal' },
							...mdInline(it),
						),
					),
				),
			);
			continue;
		}
		const para = [line];
		i++;
		while (i < lines.length && lines[i].trim() && !isSpecial(lines[i])) {
			para.push(lines[i]);
			i++;
		}
		const inline: ReactNode[] = [];
		para.forEach((p, j) => {
			if (j) {
				inline.push(h('br', { key: 'br' + j }));
			}
			mdInline(p).forEach((n) => inline.push(n));
		});
		blocks.push(
			h(
				'p',
				{
					key: key++,
					className: 'mb-2 mt-0 text-[14px] leading-[1.45]',
				},
				...inline,
			),
		);
	}
	return blocks;
}
