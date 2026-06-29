/**
 * Gallery.vue — image preview modal. Had no unit or e2e coverage. Covers the
 * images computed (filters the cwd to images), imageSrc (download-link
 * delegation), and the mounted currentItem seed.
 */

import { shallowMount } from '@vue/test-utils'
import Gallery from '@/views/partials/Gallery.vue'

function mountGallery(item, content) {
  return shallowMount(Gallery, {
    propsData: { item },
    mocks: {
      $store: { state: { cwd: { content } } },
      isImage: name => /\.(png|jpe?g|gif)$/i.test(name),
      getDownloadLink: path => '/download' + path,
    },
    directives: { lazy: () => {} }, // v-lazy (vue-lazyload) is irrelevant here
  })
}

describe('Gallery.vue', () => {
  const png = { name: 'a.png', path: '/a.png', type: 'file' }
  const jpg = { name: 'b.jpg', path: '/b.jpg', type: 'file' }
  const txt = { name: 'c.txt', path: '/c.txt', type: 'file' }

  it('seeds currentItem from the item prop on mount', () => {
    const wrapper = mountGallery(jpg, [png, jpg, txt])
    expect(wrapper.vm.currentItem).toBe(jpg)
  })

  it('images computed includes only image entries from the cwd', () => {
    const wrapper = mountGallery(png, [png, jpg, txt])
    expect(wrapper.vm.images.map(i => i.name)).toEqual(['a.png', 'b.jpg'])
  })

  it('imageSrc delegates to getDownloadLink', () => {
    const wrapper = mountGallery(png, [png])
    expect(wrapper.vm.imageSrc('/x/y.png')).toBe('/download/x/y.png')
  })
})
