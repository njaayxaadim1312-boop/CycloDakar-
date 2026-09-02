import { defineConfig } from 'vitest/config'
import { fileURLToPath } from 'node:url'

/**
 * Tests du web.
 *
 * `environment: 'node'` et non `jsdom`, volontairement.
 *
 * Ce qui est testé ici, c'est la LOGIQUE : l'algorithme GPS qui tourne dans le
 * navigateur du membre, et la mise en forme des montants. Ni l'un ni l'autre
 * n'a besoin d'un DOM, et charger jsdom pour les faire tourner ajouterait une
 * seconde de démarrage et une pile de dépendances pour rien.
 *
 * Le rendu des écrans, lui, est vérifié autrement : dans un VRAI Chrome piloté
 * au DevTools Protocol, avec une session authentifiée. jsdom ne sait pas
 * calculer une mise en page et ne dit donc rien de ce qui plante réellement à
 * l'affichage — la leçon a été apprise en mesurant l'écran de connexion.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
