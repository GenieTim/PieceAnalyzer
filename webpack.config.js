const Encore = require('@symfony/webpack-encore').default || require('@symfony/webpack-encore');

Encore
        // the project directory where compiled assets will be stored
        .setOutputPath('public/build/')
        // the public path used by the web server to access the previous directory
        .setPublicPath('/build')
        .cleanupOutputBeforeBuild()
        .enableSourceMaps(!Encore.isProduction())
        // create hashed filenames (e.g. app.abc123.css) in production
        .enableVersioning(Encore.isProduction())

        // define the assets of the project
        .addEntry('app', './assets/js/main.js')
        .addStyleEntry('css/global', './assets/css/global.scss')
        //
        .enableSingleRuntimeChunk()

        // Enable Sass loader
        .enableSassLoader()

        // show OS notifications when builds finish/fail in dev only

        // show OS notifications when builds finish/fail in dev only
        .enableBuildNotifications(!Encore.isProduction())
        ;

module.exports = Encore.getWebpackConfig();
