/**
 * Dealer Inventory for AutoScout24 – front-end script.
 *
 * Progressive enhancement: every inventory works without this file (filters
 * submit as GET, pagination uses links). The script adds instant results,
 * make / model modes, range sliders, the mobile filter drawer, the view and
 * page-size switches, "load more" / infinite scrolling and the photo
 * gallery of vehicle detail pages.
 */
( function () {
	'use strict';

	var cfg = window.DinvInventory || {};
	var L = cfg.labels || {};
	var lang = document.documentElement.lang || undefined;
	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var FILTER_PARAMS = [ 'category', 'make', 'model', 'version', 'fuel', 'transmission', 'body', 'drive', 'condition', 'warranty', 'price_from', 'price_to', 'year_from', 'year_to', 'mileage_from', 'mileage_to', 'power_from', 'power_to' ];
	var STATE_PARAMS = FILTER_PARAMS.concat( [ 'page', 'sort', 'per_page', 'view' ] );

	function fmt( value ) {
		return Number( value ).toLocaleString( lang );
	}

	function debounce( fn, delay ) {
		var timer;
		var wrapped = function () {
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( null, args );
			}, delay );
		};
		wrapped.cancel = function () {
			window.clearTimeout( timer );
		};
		return wrapped;
	}

	function parseJSON( text, fallback ) {
		if ( ! text ) {
			return fallback;
		}
		try {
			var parsed = JSON.parse( text );
			return parsed && typeof parsed === 'object' ? parsed : fallback;
		} catch ( e ) {
			return fallback;
		}
	}

	function norm( text ) {
		return String( text || '' ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	function storage( key, value ) {
		try {
			if ( value === undefined ) {
				return window.localStorage.getItem( key );
			}
			if ( value === null ) {
				window.localStorage.removeItem( key );
			} else {
				window.localStorage.setItem( key, value );
			}
		} catch ( e ) {
			// Storage blocked: the choice is simply not remembered.
		}
		return null;
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( name ) {
			if ( attrs[ name ] !== null && attrs[ name ] !== undefined && attrs[ name ] !== false ) {
				node.setAttribute( name, attrs[ name ] === true ? '' : attrs[ name ] );
			}
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function withCount( label, count, show ) {
		return show ? label + ' (' + fmt( count ) + ')' : label;
	}

	/* ------------------------------------------------------------------
	 * Make and model
	 * ---------------------------------------------------------------- */

	function MakeModel( field, onChange ) {
		this.field = field;
		this.onChange = onChange;
		this.mode = field.dataset.dinvMakeMode || 'separate';
		this.showCounts = field.dataset.counts === '1';
		this.hideEmpty = field.dataset.hideEmpty === '1';
		this.fixedMake = field.dataset.makeFixed || '';
		this.makeSel = field.querySelector( '[data-dinv-make]' );
		this.modelSel = field.querySelector( '[data-dinv-model]' );

		var data = parseJSON( ( field.querySelector( '[data-dinv-tree]' ) || {} ).textContent, {} );
		this.tree = Array.isArray( data.tree ) ? data.tree : [];
		this.facets = data.facets || { makes: {}, models: {} };

		var self = this;
		if ( this.makeSel ) {
			this.makeSel.addEventListener( 'change', function ( event ) {
				event.stopPropagation();
				self.fillModels( '' );
				self.sync();
				self.onChange();
			} );
		}
		if ( this.modelSel ) {
			this.modelSel.addEventListener( 'change', function ( event ) {
				event.stopPropagation();
				self.sync();
				self.onChange();
			} );
		}

		if ( this.mode === 'combined' && ! this.fixedMake ) {
			this.buildCombined();
		} else if ( this.mode === 'searchable' && ! this.fixedMake ) {
			this.buildCombobox();
		}
	}

	MakeModel.prototype.make = function () {
		return this.fixedMake || ( this.makeSel ? this.makeSel.value : '' );
	};

	MakeModel.prototype.model = function () {
		return this.modelSel ? this.modelSel.value : '';
	};

	MakeModel.prototype.makeCount = function ( make ) {
		return Number( ( this.facets.makes || {} )[ make ] || 0 );
	};

	MakeModel.prototype.modelCount = function ( make, model ) {
		return Number( ( ( this.facets.models || {} )[ make ] || {} )[ model ] || 0 );
	};

	MakeModel.prototype.entry = function ( make ) {
		for ( var i = 0; i < this.tree.length; i++ ) {
			if ( this.tree[ i ].value === make ) {
				return this.tree[ i ];
			}
		}
		return null;
	};

	/** Options to offer: [{value, label, count, models: [...]}], empty ones hidden or marked. */
	MakeModel.prototype.options = function () {
		var self = this;
		var make = this.make();
		var model = this.model();
		return this.tree
			.map( function ( entry ) {
				var models = entry.models
					.map( function ( m ) {
						return { value: m.value, label: m.label, count: self.modelCount( entry.value, m.value ) };
					} )
					.filter( function ( m ) {
						return ! self.hideEmpty || m.count > 0 || ( entry.value === make && m.value === model );
					} );
				return { value: entry.value, label: entry.label, count: self.makeCount( entry.value ), models: models };
			} )
			.filter( function ( entry ) {
				return ! self.hideEmpty || entry.count > 0 || entry.value === make;
			} );
	};

	MakeModel.prototype.fillMakes = function () {
		if ( ! this.makeSel ) {
			return;
		}
		var current = this.makeSel.value;
		var select = this.makeSel;
		var show = this.showCounts;
		select.textContent = '';
		select.appendChild( el( 'option', { value: '' }, L.all_makes || '' ) );
		this.options().forEach( function ( entry ) {
			var option = el( 'option', { value: entry.value, disabled: entry.count === 0 && entry.value !== current }, withCount( entry.label, entry.count, show ) );
			option.selected = entry.value === current;
			select.appendChild( option );
		} );
	};

	MakeModel.prototype.fillModels = function ( keep ) {
		if ( ! this.modelSel ) {
			return;
		}
		var make = this.make();
		var select = this.modelSel;
		var show = this.showCounts;
		var current = keep === undefined ? select.value : keep;
		var entry = null;
		this.options().forEach( function ( item ) {
			if ( item.value === make ) {
				entry = item;
			}
		} );
		select.textContent = '';
		select.appendChild( el( 'option', { value: '' }, make ? L.all_models || '' : L.choose_make_first || '' ) );
		if ( entry ) {
			entry.models.forEach( function ( m ) {
				var option = el( 'option', { value: m.value, disabled: m.count === 0 && m.value !== current }, withCount( m.label, m.count, show ) );
				option.selected = m.value === current;
				select.appendChild( option );
			} );
		}
		select.disabled = ! make;
	};

	MakeModel.prototype.update = function ( facets ) {
		if ( facets ) {
			this.facets = facets;
		}
		this.fillMakes();
		this.fillModels();
		this.sync();
	};

	MakeModel.prototype.reset = function () {
		if ( this.makeSel ) {
			this.makeSel.value = '';
		}
		this.fillModels( '' );
		this.sync();
	};

	/** Mirror the native selects into the enhanced control. */
	MakeModel.prototype.sync = function () {
		if ( this.combined ) {
			this.fillCombined();
		}
		if ( this.input ) {
			this.input.value = this.selectionLabel();
			this.clearButton.hidden = this.input.value === '';
		}
	};

	MakeModel.prototype.selectionLabel = function () {
		var entry = this.entry( this.make() );
		if ( ! entry ) {
			return '';
		}
		var model = this.model();
		for ( var i = 0; i < entry.models.length; i++ ) {
			if ( entry.models[ i ].value === model ) {
				return entry.label + ' ' + entry.models[ i ].label;
			}
		}
		return entry.label;
	};

	MakeModel.prototype.choose = function ( make, model ) {
		if ( this.makeSel ) {
			this.makeSel.value = make;
		}
		this.fillModels( model || '' );
		if ( this.modelSel ) {
			this.modelSel.value = model || '';
		}
		this.sync();
		this.onChange();
	};

	/* Combined: one select with optgroups. */
	MakeModel.prototype.buildCombined = function () {
		var self = this;
		var id = ( this.makeSel && this.makeSel.id ? this.makeSel.id : 'dinv-make' ) + '-combined';
		var wrap = el( 'div', { class: 'dinv-make-model__combined' } );
		wrap.appendChild( el( 'label', { class: 'dinv-field__label', for: id }, L.make + ' / ' + L.model ) );
		var span = el( 'span', { class: 'dinv-select' } );
		this.combined = el( 'select', { id: id } );
		span.appendChild( this.combined );
		wrap.appendChild( span );
		this.field.insertBefore( wrap, this.field.firstChild );
		this.field.classList.add( 'is-enhanced' );
		this.combined.addEventListener( 'change', function () {
			var parts = self.combined.value.split( '|' );
			self.choose( parts[ 0 ] || '', parts[ 1 ] || '' );
		} );
		this.fillCombined();
	};

	MakeModel.prototype.fillCombined = function () {
		var select = this.combined;
		var current = this.make() + ( this.model() ? '|' + this.model() : '' );
		var show = this.showCounts;
		select.textContent = '';
		select.appendChild( el( 'option', { value: '' }, L.all_makes || '' ) );
		this.options().forEach( function ( entry ) {
			var group = el( 'optgroup', { label: entry.label } );
			var all = el( 'option', { value: entry.value, disabled: entry.count === 0 && current.split( '|' )[ 0 ] !== entry.value }, withCount( entry.label + ' – ' + ( L.all_models || '' ), entry.count, show ) );
			group.appendChild( all );
			entry.models.forEach( function ( m ) {
				var value = entry.value + '|' + m.value;
				group.appendChild( el( 'option', { value: value, disabled: m.count === 0 && current !== value }, withCount( entry.label + ' ' + m.label, m.count, show ) ) );
			} );
			select.appendChild( group );
		} );
		select.value = current;
		if ( select.value !== current ) {
			select.value = '';
		}
	};

	/* Searchable: ARIA 1.2 combobox with a listbox popup. */
	MakeModel.prototype.buildCombobox = function () {
		var self = this;
		var base = this.makeSel && this.makeSel.id ? this.makeSel.id : 'dinv-make';
		var inputId = base + '-search';
		var listId = base + '-listbox';

		var wrap = el( 'div', { class: 'dinv-make-model__search' } );
		wrap.appendChild( el( 'label', { class: 'dinv-field__label', for: inputId }, L.make + ' / ' + L.model ) );
		var box = el( 'div', { class: 'dinv-combobox' } );
		this.input = el( 'input', {
			id: inputId,
			class: 'dinv-input dinv-combobox__input',
			type: 'text',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId,
			autocomplete: 'off',
			spellcheck: 'false',
			placeholder: L.make_model_search || '',
		} );
		box.insertAdjacentHTML( 'beforeend', '<svg class="dinv-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' );
		box.appendChild( this.input );
		this.clearButton = el( 'button', { type: 'button', class: 'dinv-icon-button dinv-combobox__clear', 'aria-label': L.reset || '' } );
		this.clearButton.innerHTML = '<svg class="dinv-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
		box.appendChild( this.clearButton );
		this.list = el( 'ul', { id: listId, class: 'dinv-combobox__list', role: 'listbox', 'aria-label': L.make + ' / ' + L.model, hidden: true } );
		box.appendChild( this.list );
		wrap.appendChild( box );
		this.field.insertBefore( wrap, this.field.firstChild );
		this.field.classList.add( 'is-enhanced' );

		this.active = -1;
		this.items = [];

		this.input.addEventListener( 'input', function () {
			self.renderList( self.input.value );
			self.open( true );
		} );
		this.input.addEventListener( 'focus', function () {
			self.input.select();
		} );
		this.input.addEventListener( 'click', function () {
			self.renderList( '' );
			self.open( true );
		} );
		this.input.addEventListener( 'keydown', function ( event ) {
			var key = event.key;
			if ( key === 'ArrowDown' || key === 'ArrowUp' ) {
				event.preventDefault();
				if ( self.list.hidden ) {
					self.renderList( '' );
					self.open( true );
				}
				self.move( key === 'ArrowDown' ? 1 : -1 );
			} else if ( key === 'Enter' ) {
				if ( ! self.list.hidden && self.active >= 0 ) {
					event.preventDefault();
					self.pick( self.items[ self.active ] );
				}
			} else if ( key === 'Escape' ) {
				if ( ! self.list.hidden ) {
					event.preventDefault();
					event.stopPropagation();
					self.open( false );
					self.sync();
				}
			} else if ( key === 'Tab' ) {
				self.open( false );
				self.sync();
			}
		} );
		this.list.addEventListener( 'mousedown', function ( event ) {
			event.preventDefault();
		} );
		this.list.addEventListener( 'click', function ( event ) {
			var li = event.target.closest( '[role="option"]' );
			if ( li ) {
				self.pick( self.items[ Number( li.dataset.index ) ] );
			}
		} );
		this.clearButton.addEventListener( 'click', function () {
			self.choose( '', '' );
			self.input.focus();
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( ! box.contains( event.target ) && ! self.list.hidden ) {
				self.open( false );
				self.sync();
			}
		} );
		this.sync();
	};

	MakeModel.prototype.open = function ( open ) {
		this.list.hidden = ! open;
		this.input.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( ! open ) {
			this.input.removeAttribute( 'aria-activedescendant' );
			this.active = -1;
		}
	};

	MakeModel.prototype.renderList = function ( query ) {
		var q = norm( query ).trim();
		var show = this.showCounts;
		var items = [];
		this.options().forEach( function ( entry ) {
			var makeMatch = ! q || norm( entry.label ).indexOf( q ) !== -1;
			var models = entry.models.filter( function ( m ) {
				return ! q || makeMatch || norm( entry.label + ' ' + m.label ).indexOf( q ) !== -1 || norm( m.label ).indexOf( q ) !== -1;
			} );
			if ( makeMatch ) {
				items.push( { make: entry.value, model: '', label: entry.label, count: entry.count, isMake: true } );
			}
			if ( makeMatch || models.length ) {
				// Without a query only makes are listed; typing reveals models.
				if ( q ) {
					models.forEach( function ( m ) {
						items.push( { make: entry.value, model: m.value, label: entry.label + ' ' + m.label, count: m.count, isMake: false } );
					} );
				}
			}
		} );
		this.items = items;
		this.list.textContent = '';
		var listId = this.list.id;
		var make = this.make();
		var model = this.model();
		var list = this.list;
		if ( ! items.length ) {
			list.appendChild( el( 'li', { class: 'dinv-combobox__empty', role: 'presentation' }, L.no_matches || '' ) );
		}
		items.forEach( function ( item, index ) {
			var li = el( 'li', {
				id: listId + '-' + index,
				role: 'option',
				class: 'dinv-combobox__option ' + ( item.isMake ? 'is-make' : 'is-model' ),
				'aria-selected': item.make === make && item.model === model ? 'true' : 'false',
				'aria-disabled': item.count === 0 ? 'true' : null,
				'data-index': String( index ),
			} );
			li.appendChild( el( 'span', {}, item.label ) );
			if ( show ) {
				li.appendChild( el( 'small', {}, fmt( item.count ) ) );
			}
			list.appendChild( li );
		} );
		this.active = -1;
		if ( q && items.length ) {
			this.move( 1 );
		}
	};

	MakeModel.prototype.move = function ( step ) {
		if ( ! this.items.length ) {
			return;
		}
		this.active = ( this.active + step + this.items.length ) % this.items.length;
		var self = this;
		Array.prototype.forEach.call( this.list.querySelectorAll( '[role="option"]' ), function ( li, index ) {
			li.classList.toggle( 'is-active', index === self.active );
			if ( index === self.active ) {
				self.input.setAttribute( 'aria-activedescendant', li.id );
				li.scrollIntoView( { block: 'nearest' } );
			}
		} );
	};

	MakeModel.prototype.pick = function ( item ) {
		if ( ! item ) {
			return;
		}
		this.open( false );
		this.choose( item.make, item.model );
	};

	/* ------------------------------------------------------------------
	 * Range slider
	 * ---------------------------------------------------------------- */

	function Range( fieldset, onChange ) {
		var self = this;
		this.min = Number( fieldset.dataset.min );
		this.max = Number( fieldset.dataset.max );
		this.unit = fieldset.dataset.unit || '';
		this.sliders = fieldset.querySelector( '[data-dinv-sliders]' );
		this.from = fieldset.querySelector( '[data-dinv-slider="from"]' );
		this.to = fieldset.querySelector( '[data-dinv-slider="to"]' );
		this.fromInput = fieldset.querySelector( '[data-dinv-input="from"]' );
		this.toInput = fieldset.querySelector( '[data-dinv-input="to"]' );
		this.fill = fieldset.querySelector( '[data-dinv-fill]' );
		if ( ! this.sliders || ! this.from || ! this.to || ! ( this.max > this.min ) ) {
			return;
		}
		this.sliders.hidden = false;

		var slide = function ( event ) {
			var a = Number( self.from.value );
			var b = Number( self.to.value );
			if ( a > b ) {
				if ( event.target === self.from ) {
					self.from.value = String( b );
				} else {
					self.to.value = String( a );
				}
			}
			self.fromInput.value = Number( self.from.value ) <= self.min ? '' : self.from.value;
			self.toInput.value = Number( self.to.value ) >= self.max ? '' : self.to.value;
			self.paint();
			onChange();
		};
		this.from.addEventListener( 'input', slide );
		this.to.addEventListener( 'input', slide );
		[ this.fromInput, this.toInput ].forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				self.read();
			} );
		} );
		this.read();
	}

	Range.prototype.read = function () {
		if ( ! this.from ) {
			return;
		}
		this.from.value = this.fromInput.value === '' ? String( this.min ) : this.fromInput.value;
		this.to.value = this.toInput.value === '' ? String( this.max ) : this.toInput.value;
		this.paint();
	};

	Range.prototype.paint = function () {
		var span = this.max - this.min;
		var a = ( ( Number( this.from.value ) - this.min ) / span ) * 100;
		var b = ( ( Number( this.to.value ) - this.min ) / span ) * 100;
		if ( this.fill ) {
			this.fill.style.setProperty( '--from', a + '%' );
			this.fill.style.setProperty( '--to', b + '%' );
		}
		var unit = this.unit;
		var year = unit === '';
		[ this.from, this.to ].forEach( function ( slider ) {
			var text = year ? slider.value : fmt( slider.value ) + ' ' + unit;
			slider.setAttribute( 'aria-valuetext', text.trim() );
		} );
	};

	/* ------------------------------------------------------------------
	 * Inventory
	 * ---------------------------------------------------------------- */

	function Inventory( root ) {
		this.root = root;
		this.instance = ( root.dataset.instance || '' ).replace( /[^a-z0-9_-]/g, '' );
		this.prefix = this.instance ? 'dinv_' + this.instance + '_' : 'dinv_';
		this.form = root.querySelector( '[data-dinv-form]' );
		this.panel = root.querySelector( '[data-dinv-panel]' ) || this.form;
		this.results = root.querySelector( '[data-dinv-results]' );
		this.wrap = root.querySelector( '[data-dinv-results-wrap]' );
		this.count = root.querySelector( '[data-dinv-count]' );
		this.countLabel = root.querySelector( '[data-dinv-count-label]' );
		this.pagination = root.querySelector( '[data-dinv-pagination]' );
		this.empty = root.querySelector( '[data-dinv-empty]' );
		this.error = root.querySelector( '[data-dinv-error]' );
		this.loading = root.querySelector( '[data-dinv-loading]' );
		this.status = root.querySelector( '[data-dinv-status]' );
		this.toggle = root.querySelector( '[data-dinv-filters-toggle]' );
		this.badge = root.querySelector( '[data-dinv-active-count]' );
		this.backdrop = root.querySelector( '[data-dinv-drawer-backdrop]' );
		this.config = parseJSON( root.dataset.config, {} );
		this.version = root.dataset.version || '0';
		this.urlState = root.dataset.urlState !== '0';
		this.paginationType = root.dataset.pagination || 'numbers';
		this.presetSort = root.dataset.presetSort || '';
		this.perPageDefault = root.dataset.perPageDefault || '';
		this.view = root.dataset.view || '';
		this.cache = {};
		this.controller = null;
		this.page = this.currentPage();
		this.userActed = false;

		if ( ! this.form || root.dataset.interactive === '0' ) {
			return;
		}

		root.classList.add( 'dinv-is-ready' );

		// Mark the entry so "back" to the first state also restores the list.
		if ( this.urlState && window.history.replaceState && ! ( window.history.state && window.history.state.dinv ) ) {
			window.history.replaceState( Object.assign( {}, window.history.state || {}, { dinv: true } ), '' );
		}

		var self = this;
		this.loadSoon = debounce( function () {
			self.load( 1 );
		}, 450 );

		var makeField = root.querySelector( '[data-dinv-make-mode]' );
		if ( makeField ) {
			this.makeModel = new MakeModel( makeField, function () {
				self.loadSoon.cancel();
				self.load( 1 );
			} );
		}

		this.ranges = [];
		Array.prototype.forEach.call( root.querySelectorAll( '[data-dinv-range="slider"]' ), function ( fieldset ) {
			self.ranges.push( new Range( fieldset, self.loadSoon ) );
		} );

		this.bind();
		this.updateBadge();
		this.restoreView();
		this.observeInfinite();
		this.freshness();
	}

	Inventory.prototype.currentPage = function () {
		var active = this.pagination && this.pagination.querySelector( '[aria-current="page"]' );
		if ( active ) {
			return Number( active.dataset.page ) || 1;
		}
		var more = this.pagination && this.pagination.querySelector( '[data-dinv-more]' );
		if ( more ) {
			return Math.max( 1, Number( more.dataset.page ) - 1 );
		}
		return 1;
	};

	/** Visitor state as unprefixed request parameters. */
	Inventory.prototype.state = function () {
		var params = new URLSearchParams();
		var prefix = this.prefix;
		var data = new FormData( this.form );
		data.forEach( function ( value, key ) {
			if ( key.indexOf( prefix ) !== 0 || value === '' ) {
				return;
			}
			var param = key.slice( prefix.length );
			if ( STATE_PARAMS.indexOf( param ) !== -1 ) {
				params.set( 'dinv_' + param, String( value ) );
			}
		} );
		if ( params.get( 'dinv_sort' ) === this.presetSort ) {
			params.delete( 'dinv_sort' );
		}
		if ( params.get( 'dinv_per_page' ) === this.perPageDefault ) {
			params.delete( 'dinv_per_page' );
		}
		params.delete( 'dinv_page' );
		params.delete( 'dinv_view' );
		if ( this.view ) {
			params.set( 'dinv_view', this.view );
		}
		return params;
	};

	Inventory.prototype.baseUrl = function () {
		var url = new URL( window.location.href );
		var prefix = this.prefix;
		STATE_PARAMS.forEach( function ( param ) {
			url.searchParams.delete( prefix + param );
		} );
		return url.pathname + url.search;
	};

	Inventory.prototype.requestParams = function ( page, part ) {
		var params = this.state();
		var config = this.config;
		Object.keys( config ).forEach( function ( key ) {
			params.set( key, String( config[ key ] ) );
		} );
		if ( page > 1 ) {
			params.set( 'dinv_page', String( page ) );
		}
		if ( part ) {
			params.set( 'dinv_part', part );
		}
		params.set( 'dinv_base', this.baseUrl() );
		if ( this.root.dataset.locale ) {
			params.set( 'dinv_locale', this.root.dataset.locale );
		}
		params.set( 'v', this.version );
		return params;
	};

	Inventory.prototype.fetch = function ( params, signal ) {
		var key = params.toString();
		if ( this.cache[ key ] ) {
			return Promise.resolve( this.cache[ key ] );
		}
		var self = this;
		return window
			.fetch( cfg.restBase + '/vehicles?' + key, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: signal } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data || ! data.success ) {
					throw new Error( 'Invalid response' );
				}
				self.cache[ key ] = data;
				return data;
			} );
	};

	Inventory.prototype.busy = function ( on, visible ) {
		if ( this.wrap ) {
			this.wrap.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		}
		if ( this.loading ) {
			this.loading.hidden = ! ( on && visible );
		}
	};

	/**
	 * Load a result page.
	 *
	 * @param {number} page    Page number.
	 * @param {Object} options append (load more), push (history), quiet (background refresh).
	 */
	Inventory.prototype.load = function ( page, options ) {
		options = options || {};
		if ( ! cfg.restBase ) {
			return Promise.resolve();
		}
		if ( ! options.quiet ) {
			this.userActed = true;
		}
		if ( this.controller ) {
			this.controller.abort();
		}
		this.controller = window.AbortController ? new window.AbortController() : null;
		this.busy( true, ! options.quiet );
		if ( this.error ) {
			this.error.hidden = true;
		}

		var self = this;
		var params = this.requestParams( page, options.append ? 'items' : '' );
		return this.fetch( params, this.controller ? this.controller.signal : undefined )
			.then( function ( data ) {
				self.render( data, page, options );
				self.busy( false );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}
				self.busy( false );
				// Keep the rendered vehicles; show the error only when there are none.
				if ( self.error && ( ! self.results || ! self.results.querySelector( '[data-dinv-items]' ) || ! options.quiet ) ) {
					self.error.hidden = false;
				}
			} );
	};

	/**
	 * Update the counts of the choice filters (fuel, body type, …): each
	 * option shows how many vehicles it would give with the other filters.
	 * Options without vehicles are disabled (or hidden), except the chosen one.
	 */
	Inventory.prototype.updateChoices = function ( choices ) {
		var selects = this.root.querySelectorAll( 'select[data-dinv-choice]' );
		Array.prototype.forEach.call( selects, function ( select ) {
			var counts = choices[ select.dataset.dinvChoice ];
			if ( ! counts ) {
				return;
			}
			var showCounts = select.dataset.counts === '1';
			var hideEmpty = select.dataset.hideEmpty === '1';
			Array.prototype.forEach.call( select.options, function ( option ) {
				if ( ! option.value ) {
					return;
				}
				var count = Number( counts[ option.value ] || 0 );
				var empty = count === 0 && ! option.selected;
				var label = option.dataset.label || option.textContent;
				option.textContent = showCounts ? label + ' (' + fmt( count ) + ')' : label;
				option.disabled = empty;
				option.hidden = empty && hideEmpty;
			} );
		} );
	};

	Inventory.prototype.render = function ( data, page, options ) {
		var items = this.results ? this.results.querySelector( '[data-dinv-items]' ) : null;
		var firstNew = null;

		if ( options.append && items ) {
			var holder = document.createElement( items.tagName === 'TBODY' ? 'tbody' : 'div' );
			if ( items.tagName === 'TBODY' ) {
				var table = document.createElement( 'table' );
				table.appendChild( holder );
			}
			holder.innerHTML = data.html || '';
			firstNew = holder.firstElementChild;
			while ( holder.firstChild ) {
				items.appendChild( holder.firstChild );
			}
		} else if ( this.results ) {
			this.results.innerHTML = data.html || '';
		}

		if ( this.pagination ) {
			this.pagination.innerHTML = data.pagination || '';
		}

		if ( data.layout ) {
			var root = this.root;
			[ 'card', 'grid', 'list', 'table' ].forEach( function ( layout ) {
				root.classList.toggle( 'dinv-layout-' + layout, layout === data.layout );
			} );
		}

		if ( this.count ) {
			this.count.textContent = fmt( data.total );
			this.count.dataset.total = String( data.total );
		}
		if ( this.countLabel && data.countLabel ) {
			this.countLabel.textContent = data.countLabel;
		}
		if ( this.empty ) {
			this.empty.hidden = Number( data.total ) !== 0;
		}
		if ( this.makeModel && data.facets ) {
			this.makeModel.update( data.facets );
		}
		if ( data.choices ) {
			this.updateChoices( data.choices );
		}

		this.page = page;
		this.updateBadge();
		this.observeInfinite();

		if ( ! options.quiet ) {
			this.announce( data );
		}
		if ( options.push !== false && ! options.quiet ) {
			this.pushUrl( options.append ? 1 : page );
		}
		if ( options.append && firstNew && options.focus ) {
			var link = firstNew.querySelector( '.dinv-vehicle__link, .dinv-vehicle__cta' );
			if ( link ) {
				link.focus();
			}
		}
	};

	Inventory.prototype.announce = function ( data ) {
		if ( ! this.status ) {
			return;
		}
		var status = this.status;
		var text = fmt( data.total ) + ' ' + ( data.countLabel || '' );
		status.textContent = '';
		window.setTimeout( function () {
			status.textContent = text.trim();
		}, 60 );
	};

	Inventory.prototype.pushUrl = function ( page ) {
		if ( ! this.urlState || ! window.history.pushState ) {
			return;
		}
		var url = new URL( window.location.href );
		var prefix = this.prefix;
		STATE_PARAMS.forEach( function ( param ) {
			url.searchParams.delete( prefix + param );
		} );
		this.state().forEach( function ( value, key ) {
			url.searchParams.set( prefix + key.slice( 5 ), value );
		} );
		if ( page > 1 && this.paginationType === 'numbers' ) {
			url.searchParams.set( prefix + 'page', String( page ) );
		}
		var next = url.pathname + url.search + url.hash;
		if ( next !== window.location.pathname + window.location.search + window.location.hash ) {
			window.history.pushState( { dinv: true }, '', next );
		}
	};

	Inventory.prototype.updateBadge = function () {
		if ( ! this.badge ) {
			return;
		}
		var count = 0;
		var seen = {};
		this.state().forEach( function ( value, key ) {
			var param = key.slice( 5 ).replace( /_(from|to)$/, '' );
			if ( FILTER_PARAMS.indexOf( key.slice( 5 ) ) !== -1 && param !== 'model' && ! seen[ param ] ) {
				seen[ param ] = true;
				count++;
			}
		} );
		this.badge.textContent = count ? String( count ) : '';
		this.badge.hidden = ! count;
	};

	Inventory.prototype.reset = function () {
		var self = this;
		Array.prototype.forEach.call( this.form.elements, function ( field ) {
			if ( ! field.name || field.name.indexOf( self.prefix ) !== 0 ) {
				return;
			}
			var param = field.name.slice( self.prefix.length );
			if ( FILTER_PARAMS.indexOf( param ) === -1 ) {
				return;
			}
			if ( field.type === 'checkbox' || field.type === 'radio' ) {
				field.checked = false;
			} else {
				field.value = '';
			}
		} );
		var sort = this.root.querySelector( '[data-dinv-sort]' );
		if ( sort && this.presetSort ) {
			sort.value = this.presetSort;
		}
		if ( this.makeModel ) {
			this.makeModel.reset();
		}
		this.ranges.forEach( function ( range ) {
			range.read();
		} );
		this.load( 1 );
	};

	/* View switch: remembered per instance in the browser. */
	Inventory.prototype.viewKey = function () {
		return 'dinv_view_' + ( this.instance || 'default' );
	};

	Inventory.prototype.setView = function ( view, remember ) {
		this.view = view;
		Array.prototype.forEach.call( this.root.querySelectorAll( '[data-dinv-view]' ), function ( button ) {
			button.setAttribute( 'aria-pressed', button.dataset.dinvView === view ? 'true' : 'false' );
		} );
		if ( remember ) {
			storage( this.viewKey(), view );
		}
	};

	Inventory.prototype.restoreView = function () {
		if ( ! this.root.querySelector( '[data-dinv-view]' ) || this.view ) {
			return;
		}
		var stored = storage( this.viewKey() );
		var layout = this.root.dataset.layoutDefault;
		var shown = layout === 'list' ? 'list' : 'grid';
		if ( ( stored === 'grid' || stored === 'list' ) && stored !== shown ) {
			this.setView( stored, false );
			this.load( this.page, { push: false, quiet: true } );
		}
	};

	/* Mobile drawer / collapsed filters. */
	Inventory.prototype.drawerMode = function () {
		return this.root.classList.contains( 'dinv-has-drawer' ) && window.matchMedia( '(max-width: 860px)' ).matches;
	};

	Inventory.prototype.openFilters = function ( open ) {
		var form = this.panel;
		form.classList.toggle( 'is-open', open );
		if ( this.toggle ) {
			this.toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( ! this.drawerMode() ) {
			return;
		}
		if ( this.backdrop ) {
			this.backdrop.hidden = ! open;
		}
		document.documentElement.style.overflow = open ? 'hidden' : '';
		if ( open ) {
			form.setAttribute( 'role', 'dialog' );
			form.setAttribute( 'aria-modal', 'true' );
			this.lastFocus = document.activeElement;
			var first = form.querySelector( '[data-dinv-drawer-close]' );
			if ( first ) {
				window.setTimeout( function () {
					first.focus();
				}, 50 );
			}
		} else {
			form.removeAttribute( 'role' );
			form.removeAttribute( 'aria-modal' );
			if ( this.lastFocus && this.lastFocus.focus ) {
				this.lastFocus.focus();
			}
		}
	};

	Inventory.prototype.trapFocus = function ( event ) {
		var focusable = Array.prototype.filter.call(
			this.panel.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), summary, [tabindex]:not([tabindex="-1"])' ),
			function ( node ) {
				return node.offsetParent !== null;
			}
		);
		if ( ! focusable.length ) {
			return;
		}
		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	Inventory.prototype.bind = function () {
		var self = this;
		var form = this.form;
		var root = this.root;

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.loadSoon.cancel();
			if ( self.panel.classList.contains( 'is-open' ) && self.drawerMode() ) {
				self.openFilters( false );
			}
			self.load( 1 );
		} );

		form.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( target.matches( '[data-dinv-slider]' ) ) {
				return;
			}
			if ( target.type === 'number' || target.type === 'search' || target.type === 'text' ) {
				self.loadSoon.cancel();
			}
			self.load( 1 );
		} );

		form.addEventListener( 'input', function ( event ) {
			var target = event.target;
			if ( ( target.type === 'number' || target.type === 'search' ) && ! target.closest( '.dinv-combobox' ) ) {
				self.loadSoon();
			}
		} );

		// Controls outside the form (sort, per page) belong to it via form="…".
		Array.prototype.forEach.call( root.querySelectorAll( '[data-dinv-sort], [data-dinv-per-page]' ), function ( select ) {
			select.addEventListener( 'change', function () {
				self.load( 1 );
			} );
		} );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-dinv-view]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				if ( button.getAttribute( 'aria-pressed' ) === 'true' ) {
					return;
				}
				self.setView( button.dataset.dinvView, true );
				self.load( self.paginationType === 'numbers' ? self.page : 1 );
			} );
		} );

		if ( this.toggle ) {
			this.toggle.addEventListener( 'click', function () {
				self.openFilters( ! self.panel.classList.contains( 'is-open' ) );
			} );
		}
		if ( this.backdrop ) {
			this.backdrop.addEventListener( 'click', function () {
				self.openFilters( false );
			} );
		}
		this.panel.addEventListener( 'keydown', function ( event ) {
			if ( ! self.panel.classList.contains( 'is-open' ) || ! self.drawerMode() ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				self.openFilters( false );
			} else if ( event.key === 'Tab' ) {
				self.trapFocus( event );
			}
		} );
		window.addEventListener(
			'resize',
			debounce( function () {
				if ( ! self.drawerMode() && self.backdrop && ! self.backdrop.hidden ) {
					self.backdrop.hidden = true;
					document.documentElement.style.overflow = '';
					self.panel.removeAttribute( 'role' );
					self.panel.removeAttribute( 'aria-modal' );
				}
			}, 200 )
		);

		root.addEventListener( 'click', function ( event ) {
			var target = event.target;

			var more = target.closest( '[data-dinv-more]' );
			if ( more ) {
				event.preventDefault();
				self.loadMore( true );
				return;
			}

			var pageLink = target.closest( '[data-page]' );
			if ( pageLink && self.pagination && self.pagination.contains( pageLink ) ) {
				event.preventDefault();
				var page = Number( pageLink.dataset.page ) || 1;
				self.load( page ).then( function () {
					if ( self.results ) {
						self.results.focus( { preventScroll: true } );
					}
				} );
				var top = root.getBoundingClientRect().top + window.pageYOffset - 24;
				window.scrollTo( { top: top, behavior: reducedMotion ? 'auto' : 'smooth' } );
				return;
			}

			if ( target.closest( '[data-dinv-drawer-close]' ) ) {
				self.openFilters( false );
				return;
			}

			if ( target.closest( '[data-dinv-reset]' ) ) {
				event.preventDefault();
				self.reset();
				return;
			}

			if ( target.closest( '[data-dinv-retry]' ) ) {
				self.cache = {};
				self.load( self.page, { push: false } );
			}
		} );
	};

	Inventory.prototype.loadMore = function ( focus ) {
		if ( this.loadingMore ) {
			return;
		}
		this.loadingMore = true;
		var self = this;
		this.load( this.page + 1, { append: true, focus: focus } ).then( function () {
			self.loadingMore = false;
		} );
	};

	Inventory.prototype.observeInfinite = function () {
		if ( this.paginationType !== 'infinite' || ! window.IntersectionObserver || ! this.pagination ) {
			return;
		}
		if ( this.observer ) {
			this.observer.disconnect();
		}
		var button = this.pagination.querySelector( '[data-dinv-more]' );
		if ( ! button ) {
			return;
		}
		var self = this;
		this.observer = new window.IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						self.loadMore( false );
					}
				} );
			},
			{ rootMargin: '400px 0px' }
		);
		this.observer.observe( button );
	};

	/**
	 * A page from a full-page cache may be older than the last sync: compare
	 * the inventory version and refresh the results once when it changed.
	 */
	Inventory.prototype.freshness = function () {
		var age = Date.now() / 1000 - Number( this.root.dataset.rendered || 0 );
		if ( Math.abs( age ) < 300 || ! cfg.restBase ) {
			return;
		}
		var self = this;
		var run = function () {
			window
				.fetch( cfg.restBase + '/status', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( status ) {
					if ( status && String( status.version ) !== self.version && ! self.userActed ) {
						self.version = String( status.version );
						self.cache = {};
						self.load( self.page, { push: false, quiet: true } );
					}
				} )
				.catch( function () {} );
		};
		if ( window.requestIdleCallback ) {
			window.requestIdleCallback( run, { timeout: 1500 } );
		} else {
			window.setTimeout( run, 400 );
		}
	};

	/* ------------------------------------------------------------------
	 * Detail page gallery
	 * ---------------------------------------------------------------- */

	function Photos( root ) {
		var track = root.querySelector( '[data-dinv-photos-track]' );
		if ( ! track ) {
			return;
		}
		var slides = track.children;
		var prev = root.querySelector( '[data-dinv-photos-prev]' );
		var next = root.querySelector( '[data-dinv-photos-next]' );
		var counter = root.querySelector( '[data-dinv-photos-counter]' );
		var thumbs = root.querySelectorAll( '[data-dinv-photos-thumb]' );
		var total = slides.length;
		var index = 0;

		var go = function ( target ) {
			index = Math.max( 0, Math.min( total - 1, target ) );
			track.scrollTo( { left: index * track.clientWidth, behavior: reducedMotion ? 'auto' : 'smooth' } );
		};

		var update = function () {
			var current = Math.round( track.scrollLeft / Math.max( 1, track.clientWidth ) );
			index = current;
			if ( counter ) {
				counter.textContent = current + 1 + ' / ' + total;
			}
			if ( prev ) {
				prev.disabled = current === 0;
			}
			if ( next ) {
				next.disabled = current >= total - 1;
			}
			Array.prototype.forEach.call( thumbs, function ( thumb, i ) {
				if ( i === current ) {
					thumb.setAttribute( 'aria-current', 'true' );
				} else {
					thumb.removeAttribute( 'aria-current' );
				}
			} );
		};

		if ( prev && next ) {
			prev.hidden = false;
			next.hidden = false;
			prev.addEventListener( 'click', function () {
				go( index - 1 );
			} );
			next.addEventListener( 'click', function () {
				go( index + 1 );
			} );
		}
		Array.prototype.forEach.call( thumbs, function ( thumb ) {
			thumb.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				go( Number( thumb.dataset.dinvPhotosThumb ) );
			} );
		} );
		track.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowRight' ) {
				event.preventDefault();
				go( index + 1 );
			} else if ( event.key === 'ArrowLeft' ) {
				event.preventDefault();
				go( index - 1 );
			}
		} );
		var frame = 0;
		track.addEventListener( 'scroll', function () {
			window.cancelAnimationFrame( frame );
			frame = window.requestAnimationFrame( update );
		} );
		update();
	}

	function backLinks() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dinv-back]' ), function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				// Coming from the list: go back to keep the visitor's filters and scroll position.
				var referrer = document.referrer;
				if ( ! referrer || window.history.length < 2 ) {
					return;
				}
				try {
					var from = new URL( referrer );
					var list = new URL( link.href );
					if ( from.origin === list.origin && from.pathname === list.pathname ) {
						event.preventDefault();
						window.history.back();
					}
				} catch ( e ) {}
			} );
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.dinv-inventory' ), function ( root ) {
			if ( ! root.dinvInventory ) {
				root.dinvInventory = new Inventory( root );
			}
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dinv-photos]' ), function ( root ) {
			if ( ! root.dinvPhotos ) {
				root.dinvPhotos = true;
				Photos( root );
			}
		} );
		backLinks();
	}

	window.addEventListener( 'popstate', function ( event ) {
		if ( event.state && event.state.dinv ) {
			window.location.reload();
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
