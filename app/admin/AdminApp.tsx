import { config } from '@shared/api';
import { App as LampPage } from '../lamp/App';
import { Cave } from '../cave/Cave';

type AdminPage = 'lamp' | 'cave';

const pageLabels: Record< AdminPage, string > = {
	lamp: 'Lamp',
	cave: 'Cave of Wonders',
};

export function AdminApp() {
	const page: AdminPage = config.page === 'cave' ? 'cave' : 'lamp';
	const lampUrl = config.lampUrl || '#';
	const caveUrl = config.caveUrl || config.settingsUrl || '#';

	return (
		<div className={ `djinn-admin-shell djinn-admin-shell--${ page }` }>
			<header className="mb-4 flex flex-wrap items-center justify-between gap-3">
				<div>
					<h1 className="m-0 text-[23px] font-normal leading-tight text-[#1d2327]">
						Djinn
					</h1>
					<p className="m-0 mt-1 text-[13px] text-[#646970]">
						{ pageLabels[ page ] }
					</p>
				</div>
				<nav
					className="flex items-center gap-1 rounded-[8px] border border-line bg-white p-1 shadow-sm"
					aria-label="Djinn sections"
				>
					<NavLink href={ lampUrl } active={ page === 'lamp' }>
						Lamp
					</NavLink>
					<NavLink href={ caveUrl } active={ page === 'cave' }>
						Cave of Wonders
					</NavLink>
				</nav>
			</header>
			<section className="djinn-admin-surface">
				{ page === 'cave' ? <Cave /> : <LampPage /> }
			</section>
		</div>
	);
}

function NavLink( {
	href,
	active,
	children,
}: {
	href: string;
	active: boolean;
	children: string;
} ) {
	return (
		<a
			className={ `rounded-[6px] px-3 py-1.5 text-[13px] font-semibold no-underline transition focus:shadow-[0_0_0_2px_rgba(34,113,177,0.35)] focus:outline-none ${
				active
					? 'bg-[#1d2327] text-white hover:text-white'
					: 'text-[#50575e] hover:bg-[#f0f0f1] hover:text-[#1d2327]'
			}` }
			href={ href }
			aria-current={ active ? 'page' : undefined }
		>
			{ children }
		</a>
	);
}
