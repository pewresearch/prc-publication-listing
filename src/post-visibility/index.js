/**
 * WordPress Dependencies
 */
import { FormToggle } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo } from '@wordpress/editor';

function PRCPostVisibility() {
	const { editPost } = useDispatch('core/editor');

	const {
		selectedPostVisibilityTerms,
		hiddenOnIndexTermId,
		hiddenOnSearchTermId,
		postType,
	} = useSelect((select) => {
		const _selectedPostVisibilityTermIds =
			select('core/editor').getEditedPostAttribute('_post_visibility') ??
			[];
		const postVisibilityTerms = select('core').getEntityRecords(
			'taxonomy',
			'_post_visibility'
		);

		// Find the term IDs for our visibility terms
		const hiddenOnIndexTerm = postVisibilityTerms?.find(
			(term) => term.slug === 'hidden-on-index'
		);
		const hiddenOnSearchTerm = postVisibilityTerms?.find(
			(term) => term.slug === 'hidden-on-search'
		);

		return {
			selectedPostVisibilityTerms: _selectedPostVisibilityTermIds,
			hiddenOnIndexTermId: hiddenOnIndexTerm?.id,
			hiddenOnSearchTermId: hiddenOnSearchTerm?.id,
			postType: select('core/editor').getCurrentPostType(),
		};
	}, []);

	const isHiddenOnIndex =
		hiddenOnIndexTermId !== null &&
		hiddenOnIndexTermId !== undefined &&
		(selectedPostVisibilityTerms ?? []).includes(hiddenOnIndexTermId);
	const isHiddenOnSearch =
		hiddenOnSearchTermId !== null &&
		hiddenOnSearchTermId !== undefined &&
		(selectedPostVisibilityTerms ?? []).includes(hiddenOnSearchTermId);

	const [seoData, setSeoData] = useEntityProp(
		'postType',
		postType,
		'prc_seo_data'
	);
	// Check if seoData has data... and if so set const hasSEOData to true...
	const hasSEOData = seoData && Object.keys(seoData).length > 0;

	const handleVisibilityToggle = (termId, isChecked) => {
		if (termId === null || termId === undefined) return;
		let newTerms = [...(selectedPostVisibilityTerms || [])];

		if (isChecked) {
			if (!newTerms.includes(termId)) {
				newTerms.push(termId);
			}
		} else {
			newTerms = newTerms.filter((id) => id !== termId);
		}

		editPost({ _post_visibility: newTerms });
	};

	return (
		<>
			<PluginPostStatusInfo>
				<label htmlFor="hide-on-index-toggle">
					Hide on Publications Archive
				</label>
				<FormToggle
					id="hide-on-index-toggle"
					checked={isHiddenOnIndex}
					help="If selected, this post will be hidden on our internal /publications."
					onChange={() =>
						handleVisibilityToggle(
							hiddenOnIndexTermId,
							!isHiddenOnIndex
						)
					}
				/>
			</PluginPostStatusInfo>
			<PluginPostStatusInfo>
				<label htmlFor="hide-on-search-toggle">
					Hide on Internal Search
				</label>
				<FormToggle
					id="hide-on-search-toggle"
					checked={isHiddenOnSearch}
					help="If selected, this post will be hidden on our internal /search."
					onChange={() =>
						handleVisibilityToggle(
							hiddenOnSearchTermId,
							!isHiddenOnSearch
						)
					}
				/>
			</PluginPostStatusInfo>
			{hasSEOData && (
				<PluginPostStatusInfo>
					<label htmlFor="hide-on-google-toggle">
						Hide from Search Engines
					</label>
					<FormToggle
						id="hide-on-google-toggle"
						checked={seoData?.noindex || false}
						onChange={() =>
							setSeoData({
								...(seoData || {}),
								noindex: !seoData?.noindex,
							})
						}
					/>
				</PluginPostStatusInfo>
			)}
		</>
	);
}

registerPlugin('prc-post-visibility', { render: PRCPostVisibility });
