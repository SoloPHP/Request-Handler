import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Solo Request Handler',
  description: 'Type-safe Request DTOs for PHP 8.2+ with validation, casting, and generators.',
  base: '/Request-Handler/',
  
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Request-Handler/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#10b981' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Request Handler' }],
    ['meta', { property: 'og:description', content: 'Type-safe Request DTOs for PHP 8.2+ with validation, casting, and generators' }],
  ],

  themeConfig: {
    logo: '/logo.svg',
    
    nav: [
      { text: 'Guide', link: '/guide/installation' },
      { text: 'Features', link: '/features/field-attribute' },
      { text: 'API', link: '/api/request-class' },
      { text: 'v3.0.0', link: 'https://github.com/solophp/request-handler/releases' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/solophp/request-handler' },
          { text: 'Packagist', link: 'https://packagist.org/packages/solophp/request-handler' },
          { text: 'SoloPHP', link: 'https://github.com/solophp' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Configuration', link: '/guide/configuration' }
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Field Attribute', link: '/features/field-attribute' },
          { text: 'Type Casting', link: '/features/type-casting' },
          { text: 'Processors', link: '/features/processors' },
          { text: 'Generators', link: '/features/generators' },
          { text: 'Nested Items', link: '/features/nested-items' },
          { text: 'Field Grouping', link: '/features/grouping' },
          { text: 'Validation', link: '/features/validation' }
        ]
      },
      {
        text: 'API Reference',
        items: [
          { text: 'Request Class', link: '/api/request-class' },
          { text: 'Field Attribute', link: '/api/field-attribute' },
          { text: 'Exceptions', link: '/api/exceptions' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/solophp/request-handler' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: `Copyright © 2025-${new Date().getFullYear()} SoloPHP`
    },

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/solophp/request-handler/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    }
  }
})
