/// <reference types="vite/client" />

type DrupalBehaviorAttach = (context: Document | HTMLElement, settings: object | null) => void
type DrupalBehaviorDetach = (
	context: Document | HTMLElement,
	settings?: object,
	trigger?: 'unload' | 'move' | 'serialize',
) => void

interface DrupalBehavior {
	attach?: DrupalBehaviorAttach
	detach?: DrupalBehaviorDetach
}

type DrupalThemeFunction = ((func: string, ...args: unknown[]) => unknown) & {
	placeholder: (str: string) => string
	progressBar?: (id: string) => string
	message?: (message: { text: string }, options: { type?: string; id?: string }) => string
	checkbox?: () => string
	verticalTab?: (settings: unknown) => string
	detailsSummarizedContentWrapper?: () => string
	detailsSummarizedContentText?: (text: string) => string
	ajaxProgressThrobber?: (message?: string) => string
	ajaxProgressIndicatorFullscreen?: () => string
	ajaxProgressMessage?: (message?: string) => string
	ajaxProgressBar?: (element: unknown) => string
	ajaxWrapperNewContent?: (newContent: unknown, ajax: unknown, response: unknown) => string
	ajaxWrapperMultipleRootElements?: (elements: unknown) => string
	[key: string]: unknown
}

type DrupalUrlFunction = ((path: string) => string) & {
	toAbsolute: (url: string) => string
	isLocal: (url: string) => boolean
}

type DrupalAjaxStatic = ((settings: unknown) => unknown) & {
	instances: unknown[]
	expired: () => void
	bindAjaxLinks: (element: HTMLElement | Document) => void
	WRAPPER_FORMAT?: string
}

type DrupalAjaxConstructor = {
	new (base: string, element: HTMLElement, elementSettings: unknown): unknown
	AJAX_REQUEST_PARAMETER?: string
	prototype: {
		execute?: () => void
		keypressResponse?: (element: HTMLElement, event: Event) => void
		eventResponse?: (element: HTMLElement, event: Event) => void
		beforeSerialize?: (element: HTMLElement, options: unknown) => void
		beforeSubmit?: (formValues: unknown, element: HTMLElement, options: unknown) => void
		beforeSend?: (xmlhttprequest: XMLHttpRequest, options: unknown) => void
		setProgressIndicatorBar?: () => void
		setProgressIndicatorThrobber?: () => void
		setProgressIndicatorFullscreen?: () => void
		commandExecutionQueue?: (response: unknown, status: string) => Promise<unknown>
		success?: (response: unknown, status: string) => void
		getEffect?: (response: unknown) => unknown
		error?: (xmlhttprequest: XMLHttpRequest, uri: string, customMessage?: string) => void
		[key: string]: unknown
	}
}

interface DrupalDialogDefinition {
	open: boolean
	returnValue: unknown
	show: () => void
	showModal: () => void
	close: (value?: unknown) => void
}

interface DrupalMessageOptions {
	id?: string
	type?: 'status' | 'error' | 'warning'
	announce?: string
	priority?: 'polite' | 'assertive'
}

interface DrupalMessageInstance {
	add: (message: string, options?: DrupalMessageOptions) => string
	select: (id: string) => Element | null
	remove: (id: string) => Element
	clear: () => void
}

type DrupalAjaxInsertResponse = {
	selector?: string
	method?: string
	data: string
	settings?: object
	dialogOptions?: Record<string, unknown>
	[key: string]: unknown
}

type DrupalAjaxSelectorResponse = {
	selector: string
	settings?: object
	[key: string]: unknown
}

type DrupalAjaxCommands = {
	insert: (ajax: unknown, response: DrupalAjaxInsertResponse, status?: number) => void
	remove: (ajax: unknown, response: DrupalAjaxSelectorResponse, status?: number) => void
	changed: (ajax: unknown, response: DrupalAjaxSelectorResponse & { asterisk?: string }, status?: number) => void
	alert: (ajax: unknown, response: { text: string }, status?: number) => void
	announce: (
		ajax: unknown,
		response: { text: string; priority?: 'polite' | 'assertive' },
		status?: number,
	) => void
	redirect: (ajax: unknown, response: { url: string }, status?: number) => void
	css: (
		ajax: unknown,
		response: DrupalAjaxSelectorResponse & { argument: Record<string, string | number> },
		status?: number,
	) => void
	settings: (
		ajax: { settings?: object },
		response: { merge: boolean; settings: object },
		status?: number,
	) => void
	data: (
		ajax: unknown,
		response: DrupalAjaxSelectorResponse & { name: string; value: string | object },
		status?: number,
	) => void
	focusFirst: (ajax: { element?: HTMLElement }, response: { selector: string }, status?: number) => void
	invoke: (
		ajax: unknown,
		response: DrupalAjaxSelectorResponse & { method: string; args: unknown[] },
		status?: number,
	) => void
	restripe: (ajax: unknown, response: DrupalAjaxSelectorResponse, status?: number) => void
	update_build_id: (
		ajax: unknown,
		response: { old: string; new: string },
		status?: number,
	) => void
	add_css: (
		ajax: unknown,
		response: { data: Array<Record<string, string>> },
		status?: number,
	) => Promise<unknown>
	message: (
		ajax: unknown,
		response: {
			messageWrapperQuerySelector: string
			message: string
			messageOptions?: DrupalMessageOptions
			clearPrevious?: boolean
		},
		status?: number,
	) => void
	add_js: (
		ajax: { settings?: object },
		response: {
			selector?: string
			data: Array<Record<string, string>>
		},
		status?: number,
	) => Promise<unknown>
	scrollTop: (ajax: unknown, response: { selector: string }, status?: number) => void
	openDialog: (
		ajax: { wrapper?: string; commands: DrupalAjaxCommands },
		response: DrupalAjaxInsertResponse & {
			selector: string
			dialogOptions?: Record<string, unknown>
		},
		status?: number,
	) => boolean | undefined
	closeDialog: (
		ajax: unknown,
		response: { selector: string; persist?: boolean },
		status?: number,
	) => void
	setDialogOption: (
		ajax: unknown,
		response: {
			selector: string
			optionName: string
			optionValue: unknown
		},
		status?: number,
	) => void
	openModalDialogWithUrl: (
		ajax: unknown,
		response: { url: string; dialogOptions?: Record<string, unknown> },
		status?: number,
	) => void
	tabledragChanged: (
		ajax: unknown,
		response: { id: string; tabledrag_instance: string },
		status?: number | string,
	) => void
	[key: string]: (ajax: unknown, response: Record<string, unknown>, status?: number | string) => unknown
}

type DrupalAjaxCommandsConstructor = {
	new (): DrupalAjaxCommands
	prototype: DrupalAjaxCommands
}

interface DrupalGlobal {
	locale: Record<string, unknown>
	behaviors: Record<string, DrupalBehavior>
	attachBehaviors: (context?: Document | HTMLElement, settings?: object) => void
	detachBehaviors: (
		context?: Document | HTMLElement,
		settings?: object,
		trigger?: 'unload' | 'move' | 'serialize',
	) => void
	throwError: (error: Error | string) => void
	debounce: (func: (...args: unknown[]) => unknown, wait: number, immediate: boolean) => (...args: unknown[]) => unknown
	checkPlain: (str: string) => string
	formatString: (str: string, args: Record<string, unknown>) => string
	stringReplace: (
		str: string,
		args: Record<string, unknown>,
		keys: string[] | null,
	) => string
	t: (
		str: string,
		args?: Record<string, string | number>,
		options?: { context?: string },
	) => string
	url: DrupalUrlFunction
	formatPlural: (
		count: number,
		singular: string,
		plural: string,
		args?: Record<string, unknown>,
		options?: { context?: string },
	) => string
	encodePath: (item: string) => string
	deprecationError: (deprecation: { message: string }) => void
	deprecatedProperty: (deprecation: {
		target: Record<string, unknown>
		deprecatedProperty: string
		message: string
	}) => Record<string, unknown>
	theme: DrupalThemeFunction
	elementIsVisible: (elem: HTMLElement) => boolean
	elementIsHidden: (elem: HTMLElement) => boolean
	announce?: (text: string, priority?: 'polite' | 'assertive') => void
	autocomplete?: {
		cache?: Record<string, unknown>
		options?: unknown
		[key: string]: unknown
	}
	displace?: ((broadcast?: boolean) => void) & {
		offsets?: Record<string, number>
		calculateOffset?: (edge: string) => number
	}
	states?: Record<string, unknown>
	tabbingManager?: unknown
	Message?: {
		new (messageWrapper?: HTMLElement | null): DrupalMessageInstance
		defaultWrapper?: () => HTMLElement
		getMessageTypeLabels?: () => Record<string, string>
		announce?: (message: string, options: DrupalMessageOptions) => void
		messageInternalWrapper?: (messageWrapper: HTMLElement) => HTMLElement
	}
	ProgressBar?: new (
		id: string,
		updateCallback?: (...args: unknown[]) => void,
		method?: string,
		errorCallback?: (...args: unknown[]) => void,
	) => unknown
	ajax?: DrupalAjaxStatic
	Ajax?: DrupalAjaxConstructor
	AjaxError?: new (xmlhttp: XMLHttpRequest, uri: string, customMessage?: string) => Error
	AjaxCommands?: DrupalAjaxCommandsConstructor
	dialog?: (element: HTMLElement | string, options?: unknown) => DrupalDialogDefinition
	offCanvas?: Record<string, unknown>
	timeDiff?: Record<string, unknown>
	tableSelect?: () => void
	tableSelectRange?: (from: HTMLElement, to: HTMLElement, state: boolean) => void
	tableDrag?: new (table: HTMLTableElement, tableSettings: unknown) => unknown
	TableResponsive?: new (...args: unknown[]) => unknown
	DropButton?: new (...args: unknown[]) => unknown
	verticalTab?: new (settings: unknown) => unknown
	DetailsSummarizedContent?: new (...args: unknown[]) => unknown
	htmx?: Record<string, unknown>
	[key: string]: unknown
}

interface JsCookieAttributes {
	/** Days from now, or an explicit date. Omitted means a session cookie. */
	expires?: number | Date
	path?: string
	domain?: string
	secure?: boolean
	sameSite?: 'strict' | 'Strict' | 'lax' | 'Lax' | 'none' | 'None'
	[key: string]: unknown
}

/** js-cookie 3.x, provided as a global by core/js-cookie. */
interface JsCookiesStatic {
	get(name: string): string | undefined
	get(): Record<string, string>
	set(name: string, value: string, attributes?: JsCookieAttributes): string | undefined
	remove(name: string, attributes?: JsCookieAttributes): void
	withConverter(converter: {
		read?: (value: string, name: string) => string
		write?: (value: string, name: string) => string
	}): JsCookiesStatic
	withAttributes(attributes: JsCookieAttributes): JsCookiesStatic
	readonly attributes: JsCookieAttributes
}

/**
 * Drupal's once() library.
 *
 * Declared by hand rather than imported from
 * web/core/assets/vendor/once/once.js: pulling that file into the program makes
 * checkJs type-check core's vendored source, which it does not survive.
 */
type OnceSelector = NodeList | Element[] | Element | string

interface OnceStatic {
	(id: string, selector: OnceSelector, context?: Document | Element): Element[]
	remove(id: string, selector: OnceSelector, context?: Document | Element): Element[]
	filter(id: string, selector: OnceSelector, context?: Document | Element): Element[]
	find(id?: string, context?: Document | Element): Element[]
}

/** The subset of htmx the components use. */
interface HtmxStatic {
	process(elt: Element | DocumentFragment | string): void
	[key: string]: unknown
}

declare var once: OnceStatic
declare var htmx: HtmxStatic
declare var Drupal: DrupalGlobal
declare var Cookies: JsCookiesStatic
