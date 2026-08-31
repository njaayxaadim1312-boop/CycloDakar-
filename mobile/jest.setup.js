/**
 * Environnement des tests mobiles.
 *
 * On simule ici les modules natifs qui n'existent pas hors d'un appareil :
 * sans ces doublures, le simple import d'un écran fait échouer Jest, et on ne
 * testerait plus rien du tout.
 */
// React 19 exige ce drapeau pour que `act()` encadre les mises a jour
// d'etat. Sans lui, chaque rendu asynchrone (chargement d'une requete,
// lecture du theme) part hors de act() et les assertions echouent.
global.IS_REACT_ACT_ENVIRONMENT = true

// Depuis la version 12.4, les matchers (`toBeOnTheScreen`...) sont fournis
// automatiquement par @testing-library/react-native : plus rien a importer.

// Trousseau sécurisé : indisponible en test, remplacé par une mémoire simple.
jest.mock('expo-secure-store', () => {
  const store = new Map()
  return {
    getItemAsync: jest.fn((key) => Promise.resolve(store.get(key) ?? null)),
    setItemAsync: jest.fn((key, value) => {
      store.set(key, value)
      return Promise.resolve()
    }),
    deleteItemAsync: jest.fn((key) => {
      store.delete(key)
      return Promise.resolve()
    }),
  }
})

jest.mock('@react-native-async-storage/async-storage', () => {
  const store = new Map()
  return {
    getItem: jest.fn((key) => Promise.resolve(store.get(key) ?? null)),
    setItem: jest.fn((key, value) => {
      store.set(key, value)
      return Promise.resolve()
    }),
    removeItem: jest.fn((key) => {
      store.delete(key)
      return Promise.resolve()
    }),
  }
})

jest.mock('expo-device', () => ({ modelName: 'Appareil de test' }))

// Les icônes SVG n'apportent rien aux assertions et alourdissent le rendu.
jest.mock('lucide-react-native', () => {
  const React = require('react')
  return new Proxy(
    {},
    { get: () => (props) => React.createElement('Icon', props) },
  )
})
