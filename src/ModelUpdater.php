<?php
namespace Czim\NestedModelUpdater;

use Czim\NestedModelUpdater\Contracts\ModelUpdaterInterface;
use Czim\NestedModelUpdater\Contracts\TemporaryIdsInterface;
use Czim\NestedModelUpdater\Data\RelationInfo;
use Czim\NestedModelUpdater\Data\UpdateResult;
use Czim\NestedModelUpdater\Exceptions\DisallowedNestedActionException;
use Czim\NestedModelUpdater\Exceptions\InvalidNestedDataException;
use Czim\NestedModelUpdater\Exceptions\ModelSaveFailureException;
use Czim\NestedModelUpdater\Exceptions\NestedModelNotFoundException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class ModelUpdater extends AbstractNestedParser implements ModelUpdaterInterface
{

    /**
     * Whether we're currently creating or just updating
     *
     * @var boolean
     */
    protected $isCreating;
    
    /**
     * Normally, the whole update is performed in a database transaction, but only
     * on the top level. If this is set to true, no transactions are used.
     *
     * @var bool
     */
    protected $noDatabaseTransaction = false;

    /**
     * Whether any belongs to relations were updated so far
     *
     * @var bool
     */
    protected $belongsTosWereUpdated = false;

    /**
     * Save options array to pass to Eloquent's save() method
     *
     * @var array
     */
    protected $saveOptions = [];
    
    /**
    * Attributes to be added
    *
    * @var array
    */
    protected $attributes = [];


    /**
     * Creates a new model with (potential) nested data
     *
     * @param array $data
     * @return UpdateResult
     * @throws ModelSaveFailureException
     */
    public function create(array $data)
    {
        $this->isCreating = true;
        $this->data       = $data;
        $this->model      = null;

        return $this->createOrUpdate();
    }

    /**
     * Updates an existing model with (potential) nested update data
     *
     * @param array       $data
     * @param mixed|Model $model        either an existing model or its ID
     * @param string      $attribute    lookup column, if not primary key, only if $model is int
     * @param array       $saveOptions  options to pass on to the save() Eloquent method
     * @return UpdateResult
     * @throws ModelSaveFailureException
     */
    public function update(array $data, $model, $attribute = null, array $saveOptions = [])
    {
        if ( ! ($model instanceof Model)) {
            $model = $this->getModelByLookupAtribute($model, $attribute);
        }

        $this->isCreating  = false;
        $this->data        = $data;
        $this->model       = $model;
        $this->saveOptions = $saveOptions;
        
        return $this->createOrUpdate();
    }

    /**
     * Performs the nested create or update action.
     * The data, model and circumstances should already be set at this point.
     *
     * @return UpdateResult
     * @throws ModelSaveFailureException
     */
    protected function createOrUpdate()
    {
        $this->relationsAnalyzed     = false;
        $this->belongsTosWereUpdated = false;

        $this->normalizeData();

        if ($this->shouldUseTransaction()) {

            $result = null;
            DB::transaction(function () use (&$result) {
                $result = $this->performCreateOrUpdateProcess();
            });

        } else {

            $result = $this->performCreateOrUpdateProcess();
        }

        return $result;
    }

    /**
     * Performs that actual create or update action processing; separated
     * so it may be performed in a database transaction;
     *
     * @return UpdateResult
     * @throws ModelSaveFailureException
     */
    protected function performCreateOrUpdateProcess()
    {
        $this->config->setParentModel($this->modelClass);
        $this->analyzeNestedRelationsData();

        if ($this->isTopLevel()) {
            $this->analyzeTemporaryIds();
        }

        $this->prepareModel();
        
        $this->setAttributes();

        // handle relationships; some need to be handled before saving the
        // model, since the foreign keys are stored in it; others can only
        // be handled afterwards, since the main model's key is stored as
        // foreign in their records.

        $this->handleBelongsToRelations();

        $this->updatedAndPersistModel();

        $this->handleHasAndBelongsToManyRelations();

        return (new UpdateResult())->setModel($this->model);
    }
    
    /**
     * Add attribute to main model to prevent Mass Assignment
     *
     * @param $key
     * @param $value
     */
    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
    }
    
    /**
     * Process all the attributes to the Model
     */
    private function setAttributes()
    {
        foreach ($this->attributes as $key => $value) {
            $this->model->setAttribute($key, $value);
        }
    }

    /**
     * Performs any normalization on the create or update data
     * Customize this to adjust the data property before the nesting
     * analysis & processing is performed.
     */
    protected function normalizeData()
    {
    }

    /**
     * Initializes temporary ids and analyzes data for any
     * nested temporary ids. Must be performed after the
     * relations are analyzed.
     */
    protected function analyzeTemporaryIds()
    {
        if ( ! $this->isHandlingTemporaryIds()) return;

        $temporaryIdKey = $this->getTemporaryIdAttributeKey();

        /** @var TemporaryIdsInterface $ids */
        $ids = app(TemporaryIdsInterface::class);
        $this->setTemporaryIds($ids);

        $dotArray = array_keys(array_dot($this->data));

        // only keep temporary id fields
        $dotArray = array_filter($dotArray, function ($dotKey) use ($temporaryIdKey) {
            return preg_match('#(^|\.)' . preg_quote($temporaryIdKey) . '$#', $dotKey);
        });


        if (count($dotArray)) {

            // for each nested occurrence of a temporary ID,
            // get the level right above it and pass it down recursively
            // for analysis & temporary ID preparation

            foreach ($dotArray as $dotKey) {
                
                $temporaryIdValue = array_get($this->data, $dotKey);

                $explodedKeys = explode('.', $dotKey);
                array_pop($explodedKeys);
                $dotKeyAbove = implode('.', $explodedKeys);

                // get the model class for the temporary id, so we can make sure it's consistently used
                $info = $this->getRelationInfoForDataKeyInDotNotation($dotKeyAbove);
                $modelClass = get_class($info->model());
                if ( ! $modelClass) continue;

                if ( ! $ids->getModelClassForId($temporaryIdValue)) {
                    $ids->setModelClassForId($temporaryIdValue, $modelClass);
                } elseif ($ids->getModelClassForId($temporaryIdValue) !== $modelClass) {
                    throw (new InvalidNestedDataException("Mixed model class usaged for temporary ID '{$temporaryIdValue}'"))
                        ->setNestedKey($dotKeyAbove);
                }

                // assign the create data for the temporary id if it is set, and
                // check for problematic data defined for it
                $data = array_get($this->data, $dotKeyAbove);
                if (count($data) > 1) {
                    $this->checkDataAttributeKeysForTemporaryId($temporaryIdValue, $data);
                    $ids->setDataForId($temporaryIdValue, array_except($data, [ $temporaryIdKey ]));
                }
                
                // track if we're ever able to create the model for which a temporary ID is used
                if ($info->isCreateAllowed()) {
                    $ids->markAllowedToCreateForId($temporaryIdValue);
                }
            }
        }

        $this->checkTemporaryIdsUsage();
    }
    

    /**
     * Prepares model property so it is ready for belongsTo relation updates.
     * When updating, the model is already retrieved and considered prepared.
     */
    protected function prepareModel()
    {
        if ( ! $this->isCreating) return;

        $modelClass  = $this->modelClass;
        $this->model = new $modelClass;
    }

    /**
     * Handles creating or updating the main model.
     *
     * @throws ModelSaveFailureException
     */
    protected function updatedAndPersistModel()
    {
        $modelData = $this->getDirectModelData();

        // if we have nothing to update, skip it
        if ( ! $this->isCreating && empty($modelData) && ! $this->belongsTosWereUpdated) {
            return;
        }

        $this->model->fill($modelData);

        // if we're saving a separate, top-level or belongs to related model,
        // we can simply save it by itself; other models should be saved
        // on their parent's relation.

        if ($this->shouldSaveModelOnParentRelation()) {
            $result = $this->parentModel->{$this->parentRelationInfo->relationMethod()}()->save(
                $this->model
            );
        } else {
            $result = $this->model->save($this->saveOptions);
        }

        if ( ! $result) {
            throw (new ModelSaveFailureException(
                "Failed persisting instance of {$this->modelClass} on "
                . ($this->isCreating ? 'create' : 'update') . ' operation'
            ))->setNestedKey($this->nestedKey);
        }
    }

    /**
     * Returns whether the current model should be saved on the parent's relation method.
     *
     * @return bool
     */
    protected function shouldSaveModelOnParentRelation()
    {
        if ( ! $this->parentModel || ! $this->parentRelationInfo) return false;

        return ! $this->parentRelationInfo->isBelongsTo();
    }

    /**
     * Handles the relations that need to be updated/created before the main
     * model is. Returns an array with results keyed by attribute.
     */
    protected function handleBelongsToRelations()
    {
        foreach ($this->relationInfo as $attribute => $info) {
            if ( ! array_has($this->data, $attribute) || ! $info->isBelongsTo()) continue;

            $this->belongsTosWereUpdated = true;

            /** @var Model|null $formerlyAssociatedModel */
            $formerlyAssociatedModel = $this->model->{$info->relationMethod()}()->first();

            $result = $this->handleNestedSingleUpdateOrCreate(
                Arr::get($this->data, $attribute),
                $info,
                $attribute
            );

            $result = ($result instanceof UpdateResult)
                ?   $result->model()
                :   $result;

            // update model by associating or dissociating as necessary
            if (    $result instanceof Model
                ||  (false !== $result && null !== $result)
            ) {
                // if the model associated now is different from the one before, delete if we should
                if (    $info->isDeleteDetached()
                    &&  $formerlyAssociatedModel
                    &&  $formerlyAssociatedModel->getKey() !== $result->getKey()
                ) {
                    $this->deleteFormerlyRelatedModel($formerlyAssociatedModel, $info);
                }

                $this->model->{$info->relationMethod()}()->associate($result);
                continue;
            }

            if ($info->isDeleteDetached() && $formerlyAssociatedModel) {
                $this->deleteFormerlyRelatedModel($formerlyAssociatedModel, $info);
            }

            $this->model->{$info->relationMethod()}()->dissociate();
        }
    }

    /**
     * Handles the relations that should be updated only after the model
     * is persisted.
     */
    protected function handleHasAndBelongsToManyRelations()
    {
        foreach ($this->relationInfo as $attribute => $info) {
            if ( ! array_has($this->data, $attribute) || $info->isBelongsTo()) continue;

            // collect keys for (newly) connected models
            $keys = [];


            if ($info->isSingular()) {

                $result = $this->handleNestedSingleUpdateOrCreate(
                    Arr::get($this->data, $attribute),
                    $info,
                    $attribute
                );

                if (    ! ($result instanceof UpdateResult)
                    ||  ! $result->model()
                    ||  ! $result->model()->getKey()
                ) {
                    continue;
                }

                $keys[] = $result->model()->getKey();

            } else {
                // plural: an array with updates or links by primary key for
                // the related records, and syncs the relation

                foreach (Arr::get($this->data, $attribute, []) as $index => $data) {

                    $result = $this->handleNestedSingleUpdateOrCreate($data, $info, $attribute, $index);

                    if (    ! ($result instanceof UpdateResult)
                        ||  ! $result->model()
                        ||  ! $result->model()->getKey()
                    ) {
                        continue;
                    }

                    $keys[] = $result->model()->getKey();
                }
            }

            
            // sync relation, detaching anything not specifically listed in the dataset
            // unless we shouldn't

            if (is_a($info->relationClass(), BelongsToMany::class, true)) {
                $this->syncKeysForBelongsToManyRelation($info, $keys);
            } else {
                $this->syncKeysForHasManyRelation($info, $keys);
            }
        }
    }

    /**
     * Synchronizes the keys for a BelongsToMany relation.
     *
     * @param RelationInfo $info
     * @param array        $keys
     */
    protected function syncKeysForBelongsToManyRelation(RelationInfo $info, array $keys)
    {
        // if we should delete detached models, gather the model ids to delete
        if ($info->isDeleteDetached()) {
            $deleteKeys = $this->model->{$info->relationMethod()}()
                ->pluck($info->model()->getTable() . '.' . $info->model()->getKeyName())
                ->toArray();

            $deleteKeys = array_diff($deleteKeys, $keys);
        }

        // detach by default (for belongs to many), unless configured otherwise
        $detaching = (null === $info->getDetachMissing()) ? true : $info->getDetachMissing();

        $this->model->{$info->relationMethod()}()->sync($keys, $detaching);

        // delete models now detached, if configured to
        if ($info->isDeleteDetached() && isset($deleteKeys) && count($deleteKeys)) {

            foreach ($deleteKeys as $key) {
                $model = $this->getModelByLookupAtribute($key, null, get_class($info->model()));
                if ( ! $model) continue;

                $this->deleteFormerlyRelatedModel($model, $info);
            }
        }
    }

    /**
     * Synchronizes the keys for a HasMany relation. This is a special case,
     * since the actual new relations should already be linked after the
     * update/create handling recursive call.
     *
     * @param RelationInfo $info
     * @param array        $keys
     */
    protected function syncKeysForHasManyRelation(RelationInfo $info, array $keys)
    {
        // the relations might be disconnected, but only if the key is nullable
        // if deletion is not configured, we should attempt setting the key to
        // null

        // if it is a has-one relation, has a different default for detaching
        $isNotHasOne = is_a($info->relationClass(), HasOne::class, true);

        // do not detach by default (for hasmany), unless configured otherwise
        $detaching = (null === $info->getDetachMissing()) ? $isNotHasOne : $info->getDetachMissing();

        if ( ! $detaching && ! $info->isDeleteDetached()) return;

        // find keys for all models that are linked to the model but omitted in nested data
        $oldKeys = $this->model->{$info->relationMethod()}()
            ->pluck($info->model()->getKeyName())
            ->toArray();

        $oldKeys = array_diff($oldKeys, $keys);

        if ( ! count($oldKeys)) return;


        if ($info->isDeleteDetached()) {

            foreach ($oldKeys as $oldKey) {
                $this->deleteFormerlyRelatedModel($info->model()->find($oldKey), $info);
            }
            return;
        }

        if ($detaching) {

            foreach ($oldKeys as $oldKey) {
                /** @var Model $model */
                $detachModel = $info->model()->find($oldKey);
                if ( ! $detachModel) continue;

                $foreignKey = $this->model->{$info->relationMethod()}()->getPlainForeignKey();

                $detachModel->{$foreignKey} = null;
                $detachModel->save();
            }
        }
    }


    /**
     * Deletes a model that was formerly related to this model.
     * This performs a check to see if the model is at least not being used
     * for the same type of relation by another model. Note that this is not
     * safe by any means -- use on your own risk.
     *
     * @param Model        $model
     * @param RelationInfo $info
     */
    protected function deleteFormerlyRelatedModel(Model $model, RelationInfo $info)
    {
        $class = $this->modelClass;

        // To see if we can safely delete the child model, attempt to find a different
        // model of the same type as the parent model, that has a relation to the child model.
        // If one exists, it's still in use and should not be deleted.

        /** @var Model $class */
        $inUse = $class::whereHas($info->relationMethod(),
            function ($query) use ($model, $info) {
                /** @var \Illuminate\Database\Eloquent\Builder $query */
                $query->where($info->model()->getTable() . '.' . $info->model()->getKeyName(), $model->id);
            })
            ->where($this->model->getTable() . '.' . $this->model->getKeyName(), '!=', $this->model->id)
            ->count();


        if ($inUse) return;

        $model->delete();
    }

    /**
     * Handles a nested update, link or create for a single model, returning
     * the result.
     *
     * @param mixed        $data
     * @param RelationInfo $info
     * @param string       $attribute
     * @param null|int     $index       optional, for to-many list indexes to append after attribute
     * @return UpdateResult|false       false if no model available
     * @throws DisallowedNestedActionException
     */
    protected function handleNestedSingleUpdateOrCreate($data, RelationInfo $info, $attribute, $index = null)
    {
        // handle model before, use results to save foreign key on the model later
        $nestedKey = $this->appendNestedKey($attribute, $index);

        $data = $this->normalizeNestedSingularData(
            $data,
            $info->model()->getKeyName(),
            $nestedKey
        );
        
        // if this data set has a temporary id, create the model and convert to link operation instead
        // note that we cannot assume that it is being created explicitly for this relation, it
        // may simply be the first time it is referenced while processing the data tree.
        if (    $this->isHandlingTemporaryIds()
            &&  $this->hasTemporaryIds()
            &&  array_key_exists($this->getTemporaryIdAttributeKey(), $data)
        ) {
            $temporaryId = $data[ $this->getTemporaryIdAttributeKey() ];

            if ( ! $this->temporaryIds->hasId($temporaryId)) {
                return $this->makeUpdateResult();
            }

            $model = $this->temporaryIds->getModelForId($temporaryId);

            if ( ! $model) {
                // if it has not been created, attempt to create it

                $data = $this->temporaryIds->getDataForId($temporaryId);

                // safeguard, this should never happen
                if (null === $data) {
                    return $this->makeUpdateResult();
                }

                $updater = $this->makeNestedParser($info->updater(), [
                    get_class($info->model()),
                    $attribute,
                    $nestedKey,
                    $this->model,
                    $this->config
                ]);

                $updateResult = $updater->create($data);

                // if for some reason the update or create was not succesful or
                // did not return a model, dissociate the relationship
                if ( ! $updateResult->model()) {
                    return $this->makeUpdateResult();
                }

                $this->temporaryIds->setModelForId($temporaryId, $updateResult->model());

                return $updateResult;
            }

            // it has been created, so can reference it here, converting the data to link-only
            $data = [ $model->getKeyName() => $model->getKey() ];
        }

        $updateId = Arr::get($data, $info->model()->getKeyName());

        // if the key is present, but the data is empty, the relation should be dissociated
        if (empty($data)) {
            return $this->makeUpdateResult();
        }

        // if we're not allowed to perform creates or updates, only handle the link
        // -- and this is not possible, stop the process or make sure it is handled right
        if ( ! $info->isUpdateAllowed()) {

            // if we cannot create it, we cannot proceed
            if (empty($updateId)) {
                throw (new DisallowedNestedActionException("Not allowed to create new for link-only nested relation"))
                    ->setNestedKey($nestedKey);
            }

            // strip everything but the key, so it is treated as a link-only operation
            $data = [ $info->model()->getKeyName() => $updateId ];
        }

        // get the existing model, if we have an update ID, or null if no match exists
        if ( ! empty($updateId)) {
            $existingModel = $this->getModelByLookupAtribute(
                $updateId,
                $info->model()->getKeyName(),
                get_class($info->model()),
                $nestedKey,
                false
            );
        } else {
            $existingModel = null;
        }

        // if a model for a given 'updateId' does not exist yet, and the model's key is
        // not an incrementing key, this should be treated as an attempt to create a record
        $creatingWithKey = ( ! $info->model()->getIncrementing() && ! empty($updateId) && ! $existingModel);

        // if this is a link-only operation, mark it
        $onlyLinking = (count($data) == 1 && ! empty($updateId) && ! $creatingWithKey);

        // if we are allowed to update, but only the key is provided, treat this as a link-only operation
        // throw an exception if we couldn't find the model
        if ( ! $info->isUpdateAllowed() || $onlyLinking) {
            if ( ! $existingModel) {
                throw (new NestedModelNotFoundException())
                    ->setModel(get_class($info->model()))
                    ->setNestedKey($nestedKey);
            }

            return $this->makeUpdateResult($existingModel);
        }

        // otherwise, create or update, depending on whether the primary key is present in the data
        // if it is a create operation, make sure we're allowed to
        if ((empty($updateId) || $creatingWithKey) && ! $info->isCreateAllowed()) {
            throw (new DisallowedNestedActionException("Not allowed to create new for update-only nested relation"))
                ->setNestedKey($nestedKey);
        }

        $updater = $this->makeNestedParser($info->updater(), [
            get_class($info->model()),
            $attribute,
            $nestedKey,
            $this->model,
            $this->config
        ]);
        
        $updateResult = (empty($updateId) || $creatingWithKey)
            ?   $updater->create($data)
            :   $updater->update($data, $updateId, $info->model()->getKeyName());

        // if for some reason the update or create was not succesful or
        // did not return a model, dissociate the relationship
        if ( ! $updateResult->model()) {
            return $this->makeUpdateResult();
        }

        return $updateResult;
    }

    /**
     * Normalizes data for a singular relationship;
     * assuming validation has already been passed.
     *
     * @param mixed       $data
     * @param string      $keyAttribute
     * @param null|string $nestedKey        child nested key that the data is for
     * @return array
     */
    protected function normalizeNestedSingularData($data, $keyAttribute = 'id', $nestedKey = null)
    {
        // data may be a scalar, in which case it is assumed
        // to be the primary key

        if (null === $data) {
            return [];
        }

        if (is_scalar($data)) {
            return [ $keyAttribute => $data ];
        }

        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        if ( ! is_array($data)) {
            throw new UnexpectedValueException(
                "Nested data should be key (scalar) or array data"
                . ($nestedKey ? " (nesting: {$nestedKey})" : '')
            );
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     * @return ModelUpdaterInterface
     */
    protected function makeNestedParser($class, array $parameters)
    {
        /** @var ModelUpdaterInterface $updater */
        $updater = App::make($class, $parameters);

        if ( ! ($updater instanceof ModelUpdaterInterface)) {

            if ( ! $updater) {
                throw new UnexpectedValueException(
                    "Expected ModelUpdaterInterface instance, got nothing for " . $class
                );
            }

            throw new UnexpectedValueException(
                "Expected ModelUpdaterInterface instance, got " . get_class($class) . ' instead'
            );
        }

        // if we're dealing with temporary IDs, pass on their tracking info
        if ($this->isHandlingTemporaryIds() && $this->hasTemporaryIds()) {
            $updater->setTemporaryIds($this->temporaryIds);
        }

        return $updater;
    }
    
    /**
     * Returns UpdateResult instance for standard precluded responses.
     *
     * @param Model $model
     * @param bool  $success
     * @return UpdateResult
     */
    protected function makeUpdateResult(Model $model = null, $success = true)
    {
        return (new UpdateResult())
            ->setModel($model)
            ->setSuccess($success);
    }

    /**
     * Returns whether the update/create should be performed in a transaction.
     *
     * @return boolean
     */
    protected function shouldUseTransaction()
    {
        if ($this->noDatabaseTransaction || ! Config::get('nestedmodelupdater.database-transactions')) {
            return false;
        }

        // if not explicitly disabled, transactions are used only for the top
        // level, so when no nested key has been set at all.
        return null === $this->nestedKey;
    }
    

    // ------------------------------------------------------------------------------
    //      Getters / Setters
    // ------------------------------------------------------------------------------

    /**
     * @return $this
     */
    public function enableDatabaseTransaction()
    {
        $this->noDatabaseTransaction = false;

        return $this;
    }

    /**
     * @return $this
     */
    public function disableDatabaseTransaction()
    {
        $this->noDatabaseTransaction = true;

        return $this;
    }

}
