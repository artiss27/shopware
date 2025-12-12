import template from './artiss-tools-landing.html.twig';
import './artiss-tools-landing.scss';

const { Component } = Shopware;

Component.register('artiss-tools-landing', {
    template,
    methods: {
        goTo(routeName) {
            this.$router.push({ name: routeName });
        }
    }
});
