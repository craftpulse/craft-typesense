// module exports
module.exports = {
    content: [
        '../src/templates/**/*.{twig,html}',
        './src/vue/**/*.{vue,html}',
    ],
    safelist: [
        'pl-4',
        'pt-4',
    ],
    theme: {
        extend: {
        }
    },
    corePlugins: {},
    plugins: [],
};
