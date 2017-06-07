import assert from 'assert'
import blockTypesReducer from '../reducers'
import {
	BLOCK_TYPE, BLOCK_TYPE_GROUP,
	ADD_BLOCK_TYPE, REMOVE_BLOCK_TYPE, MOVE_BLOCK_TYPE,
	ADD_BLOCK_TYPE_GROUP, REMOVE_BLOCK_TYPE_GROUP, MOVE_BLOCK_TYPE_GROUP,
} from '../constants'

function createDummyBlockType(id, overrides={})
{
	return Object.assign({
		id: String(id),
		name: `Block type`,
		handle: 'blockType',
		max: 0,
		topLevel: true,
		childrenIds: [],
		maxChildren: 0,
		tabs: [],
		errors: [],
		template: {
			html: '',
			css: '',
			js: '',
		},
	}, overrides)
}

describe(`Reducers`, function()
{
	describe(`blockTypesReducer()`, function()
	{
		it(`should not change the state after an invalid action`, function()
		{
			const action = {}

			const initialState = {
				collection: {},
				groups: {},
				structure: [],
			}

			const expectedState = {
				collection: {},
				groups: {},
				structure: [],
			}

			assert.deepEqual(blockTypesReducer(initialState, action), expectedState)
			assert.strictEqual(blockTypesReducer(initialState, action), initialState)
		})

		describe('ADD_BLOCK_TYPE', function()
		{
			it(`should add a block type to the store`, function()
			{
				const action = {
					type: ADD_BLOCK_TYPE,
					payload: { blockType: createDummyBlockType('1') },
				}

				const initialState = {
					collection: {},
					groups: {},
					structure: [],
				}

				const expectedState = {
					collection: { '1': createDummyBlockType('1') },
					groups: {},
					structure: [ { type: BLOCK_TYPE, id: '1' } ],
				}

				const actualState = blockTypesReducer(initialState, action)

				assert.deepEqual(actualState, expectedState)
				assert.strictEqual(actualState.groups, initialState.groups)
			})

			it(`should add a block type at the requested index`, function()
			{
				const action = {
					type: ADD_BLOCK_TYPE,
					payload: {
						blockType: createDummyBlockType('4'),
						index: 1,
					},
				}

				const initialState = {
					collection: {
						'1': createDummyBlockType('1'),
						'2': createDummyBlockType('2'),
						'3': createDummyBlockType('3'),
					},
					groups: {},
					structure: [
						{ type: BLOCK_TYPE, id: '1' },
						{ type: BLOCK_TYPE, id: '2' },
						{ type: BLOCK_TYPE, id: '3' },
					],
				}

				const expectedState = {
					collection: {
						'1': createDummyBlockType('1'),
						'2': createDummyBlockType('2'),
						'3': createDummyBlockType('3'),
						'4': createDummyBlockType('4'),
					},
					groups: {},
					structure: [
						{ type: BLOCK_TYPE, id: '1' },
						{ type: BLOCK_TYPE, id: '4' },
						{ type: BLOCK_TYPE, id: '2' },
						{ type: BLOCK_TYPE, id: '3' },
					],
				}

				const actualState = blockTypesReducer(initialState, action)

				assert.deepEqual(actualState, expectedState)
			})
		})

		describe('REMOVE_BLOCK_TYPE', function()
		{

		})

		describe('MOVE_BLOCK_TYPE', function()
		{

		})

		describe('ADD_BLOCK_TYPE_GROUP', function()
		{
			
		})

		describe('REMOVE_BLOCK_TYPE_GROUP', function()
		{

		})

		describe('MOVE_BLOCK_TYPE_GROUP', function()
		{

		})
	})
})