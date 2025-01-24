import vue from '@vitejs/plugin-vue'
import ViteRestart from 'vite-plugin-restart';
import { nodeResolve } from '@rollup/plugin-node-resolve';
import path from 'path';

// https://vitejs.dev/config/
export default ({ command }) => ({
    base: command === 'serve' ? '' : '/dist/',
    build: {
        emptyOutDir: true,
        manifest: true,
        outDir: '../src/web/assets/dist',
        rollupOptions: {
            input: {
                typesense: '/src/js/typesense.ts',
                'typesense-collections': '/src/js/typesense-collections.ts',
                'typesense-synonyms': '/src/js/typesense-synonyms.ts',
            },
            output: {
                sourcemap: true
            },
        }
    },
    plugins: [
        nodeResolve({
            moduleDirectories: [
                path.resolve('./node_modules'),
            ],
        }),
        ViteRestart({
            reload: [
                '../src/templates/**/*',
            ],
        }),
        vue(),
    ],
    publicDir: '../src/web/assets/public',
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
            vue: 'vue/dist/vue.esm-bundler.js',
        },
        preserveSymlinks: true,
    },
    server: {
        fs: {
            strict: false
        },
        host: '0.0.0.0',
        origin: 'https://plugin-playground.ddev.site/3000',
        port: 3000,
        strictPort: true,
    }
})
