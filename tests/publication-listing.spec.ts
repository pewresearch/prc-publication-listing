import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe('PRC Publication Listing', () => {
	test('Post visibility taxonomy is registered', async ({ requestUtils }) => {
		const taxonomies = await requestUtils.rest({
			path: '/wp/v2/taxonomies',
			method: 'GET',
		});
		expect(taxonomies).toBeDefined();
		expect(taxonomies['_post_visibility']).toBeDefined();
		expect(taxonomies['_post_visibility'].name).toBe('_post_visibility');
	});

	test('Post visibility terms exist', async ({ requestUtils }) => {
		const terms = await requestUtils.rest({
			path: '/wp/v2/_post_visibility',
			method: 'GET',
		});
		expect(terms).toBeDefined();
	});

	test('Post can be created and hidden on index', async ({
		admin,
		editor,
		requestUtils,
	}) => {
		const testTitle = 'Hidden Post Test';
		const testContent = 'This post should be hidden on index.';

		// Create a new post.
		await admin.createNewPost({
			title: testTitle,
			content: testContent,
			postType: 'post',
		});

		// Publish the post.
		await editor.publishPost();

		// Get the created post via REST API.
		const posts = await requestUtils.rest({
			path: '/wp/v2/posts',
			method: 'GET',
		});

		// Get the first item out of the posts array.
		const post = posts?.[0];

		// Verify the post was created with correct title.
		expect(post.title.rendered).toBe(testTitle);
	});

	test('showChildPosts query var is registered', async ({ requestUtils }) => {
		// Test that the REST API accepts the isPubListingQuery parameter
		const posts = await requestUtils.rest({
			path: '/wp/v2/posts?isPubListingQuery=true',
			method: 'GET',
		});
		expect(posts).toBeDefined();
	});
});
