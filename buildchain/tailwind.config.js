// module exports
module.exports = {
    content: [
        '../src/templates/**/*.{twig,html}',
        './src/vue/**/*.{vue,html}',
    ],
    safelist: [
        'font-bold',
        'grid-cols-4',
        'mb-0',
        'mb-2',
        'mt-0',
        'p-4',
        'pl-4',
        'pt-4',
    ],
    theme: {
        extend: {
        }
    },
    corePlugins: {},
    important: true,
    plugins: [],
};
