import { createRoot } from '@wordpress/element';
import type { ReactElement } from 'react';

export function mount( id: string, element: ReactElement ): void {
	const node = document.getElementById( id );
	if ( node ) {
		createRoot( node ).render( element );
	}
}
