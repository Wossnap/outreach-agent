import app from '../tailwind.config.js';

/*
 * The app's Tailwind config, with the React mirrors and their preview
 * compositions added to the content scan so the stylesheet shipped to
 * claude.ai/design carries every class they use. The app's own build
 * never sees these paths.
 */
export default {
    ...app,
    content: [
        '../resources/views/**/*.blade.php',
        './src/**/*.tsx',
        '../.design-sync/previews/**/*.tsx',
    ],
    /*
     * The design agent composes layouts with utilities the app may not use
     * yet, so the whole semantic colour vocabulary and the common layout
     * utilities ship whether or not a view happens to use them.
     */
    safelist: [
        {
            pattern: /^(bg|text|border|ring|divide|decoration|ring-offset)-(page|surface|band|ink|ink-dim|rule|rule-strong|brand|brand-hover|brand-ink|accent|warn|danger|cream|sand|taupe|navy|navy-deep|navy-raised|navy-ink|navy-ink-dim|navy-rule|gold|gold-deep|alert)$/,
            variants: ['hover', 'focus', 'focus-visible', 'placeholder'],
        },
        { pattern: /^(p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|ms|me|gap|gap-x|gap-y|space-x|space-y)-(0|0\.5|1|1\.5|2|3|4|5|6|8|10|12|16|auto)$/ },
        { pattern: /^(w|h|min-h|max-w)-(full|screen|auto|fit|xs|sm|md|lg|xl|2xl|4xl|7xl|4|5|6|8|10|12|16|20|24|32|40|48|56|64|72|80|96)$/ },
        { pattern: /^(flex|inline-flex|grid|block|inline-block|hidden|flex-1|flex-col|flex-row|flex-wrap|items-(start|center|end|baseline)|justify-(start|center|end|between)|shrink-0|grow|self-(start|center|end))$/ },
        { pattern: /^(grid-cols|col-span)-(1|2|3|4|5|6|12)$/, variants: ['sm', 'md', 'lg'] },
        { pattern: /^(text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|left|center|right)|font-(normal|medium|semibold|bold|sans|display|mono)|uppercase|normal-case|tracking-(tight|normal|label)|leading-(none|tight|snug|normal|relaxed)|truncate|whitespace-nowrap|break-all|underline|underline-offset-2|tabular-nums)$/ },
        { pattern: /^(rounded(-md|-card|-full|-none)?|border(-0|-2|-t|-b|-l|-r|-l-4|-dashed)?|divide-(x|y)|overflow-(hidden|auto|x-auto|y-auto)|relative|absolute|fixed|inset-0|z-(10|20|50)|transition|cursor-pointer|opacity-(50|60|70)|disabled:opacity-50|sr-only)$/ },
    ],
};
